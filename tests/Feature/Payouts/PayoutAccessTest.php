<?php

namespace Tests\Feature\Payouts;

use App\Domain\Commissions\CommissionLedger;
use App\Domain\Payouts\PayoutService;
use App\Enums\Role;
use App\Models\AffiliatePayoutMethod;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Notifications\Payouts\PayoutMethodChanged;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PayoutAccessTest extends TestCase
{
    use PayoutTestHelpers;
    use RefreshDatabase;

    public function test_payout_method_is_encrypted_masked_and_needs_the_password(): void
    {
        Notification::fake();
        $affiliate = $this->approvedAffiliate();

        $this->saveBankMethod($affiliate, ['password' => 'wrong-password'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->saveBankMethod($affiliate, ['account_number' => '12'])->assertUnprocessable()->assertJsonValidationErrors('account_number');
        $this->saveBankMethod($affiliate, ['type' => 'esewa', 'wallet_id' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('wallet_id');

        $this->saveBankMethod($affiliate)->assertOk()->assertJsonPath('data.masked', 'Nabil Bank ••••1234')->assertJsonMissingPath('data.account_number');

        $raw = DB::table('affiliate_payout_methods')->value('details');
        $this->assertStringNotContainsString('01234567891234', $raw);
        $this->assertSame('01234567891234', AffiliatePayoutMethod::sole()->details['account_number']);
        Notification::assertSentTo($affiliate->user, PayoutMethodChanged::class);

        $log = AuditLog::query()->where('action', 'payout_method.saved')->sole();
        $this->assertStringNotContainsString('01234567891234', json_encode($log->properties));

        $this->saveBankMethod($affiliate, ['type' => 'khalti', 'wallet_id' => '9841234567'])->assertOk()->assertJsonPath('data.masked', 'Khalti ••••4567');
        $this->assertSame(1, AffiliatePayoutMethod::count());
    }

    public function test_changing_details_does_not_redirect_an_open_payout(): void
    {
        $affiliate = $this->readyAffiliate();
        $id = $this->requestPayout($affiliate)->json('data.id');
        $this->saveBankMethod($affiliate, ['type' => 'esewa', 'wallet_id' => '9801112222'])->assertOk();
        $this->approve($id)->assertOk();

        $finance = $this->finance();
        $this->actingAs($finance)->getJson("/api/v1/admin/payouts/{$id}")->assertOk()
            ->assertJsonPath('data.destination', 'Nabil Bank ••••1234')
            ->assertJsonPath('data.destination_details.account_number', '01234567891234');
        $this->assertTrue(AuditLog::query()->where('action', 'payout.destination_viewed')->where('actor_id', $finance->id)->exists());
    }

    public function test_full_details_only_for_payers_while_approved(): void
    {
        $affiliate = $this->readyAffiliate();
        $id = $this->requestPayout($affiliate)->json('data.id');

        // Not approved yet: masked only, even for finance.
        $this->actingAs($this->finance())->getJson("/api/v1/admin/payouts/{$id}")->assertOk()->assertJsonPath('data.destination_details', null);
        $this->approve($id)->assertOk();

        // Affiliate managers can see payouts but not account numbers.
        $this->actingAs($this->userWithRole(Role::AffiliateManager))->getJson("/api/v1/admin/payouts/{$id}")->assertOk()
            ->assertJsonPath('data.destination_details', null)
            ->assertJsonPath('data.can_review', false)
            ->assertJsonPath('data.can_mark_paid', false);

        $this->markPaid($id)->assertOk();
        $this->actingAs($this->finance())->getJson("/api/v1/admin/payouts/{$id}")->assertJsonPath('data.destination_details', null);
        // Affiliate list never carries details.
        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/payouts')->assertOk()
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonMissingPath('data.0.destination_details')
            ->assertJsonMissingPath('data.0.account_name');
    }

    public function test_permissions(): void
    {
        $affiliate = $this->readyAffiliate();
        $id = $this->requestPayout($affiliate)->json('data.id');

        foreach ([Role::Support, Role::Customer] as $role) {
            $this->actingAs($this->userWithRole($role))->getJson('/api/v1/admin/payouts')->assertForbidden();
        }
        $manager = $this->userWithRole(Role::AffiliateManager);
        $this->actingAs($manager)->getJson('/api/v1/admin/payouts')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($manager)->postJson("/api/v1/admin/payouts/{$id}/approve")->assertForbidden();
        $this->actingAs($affiliate->user)->postJson("/api/v1/admin/payouts/{$id}/approve")->assertForbidden();

        // A customer without the affiliate role cannot request.
        $this->actingAs($this->userWithRole(Role::Customer))->postJson('/api/v1/affiliate/payouts')->assertForbidden();
    }

    public function test_staff_list_puts_open_requests_first_and_summarises(): void
    {
        $first = $this->readyAffiliate();
        $paid = $this->requestPayout($first)->json('data.id');
        $this->approve($paid)->assertOk();
        $this->markPaid($paid)->assertOk();
        $open = $this->requestPayout($this->readyAffiliate())->json('data.id');

        $finance = $this->finance();
        $this->actingAs($finance)->getJson('/api/v1/admin/payouts')->assertOk()
            ->assertJsonPath('data.0.id', $open)->assertJsonPath('data.1.id', $paid);
        $this->actingAs($finance)->getJson('/api/v1/admin/payouts?status=paid')->assertJsonCount(1, 'data');
        $this->actingAs($finance)->getJson('/api/v1/admin/payouts/summary')->assertOk()
            ->assertJsonPath('data.by_status.requested.count', 1)
            ->assertJsonPath('data.by_status.paid.amount_minor', 200000);
    }

    public function test_service_is_idempotent_against_replays(): void
    {
        $affiliate = $this->readyAffiliate();
        $id = $this->requestPayout($affiliate)->json('data.id');
        $payout = Payout::where('public_id', $id)->firstOrFail();
        $service = app(PayoutService::class);
        $finance = $this->finance();

        $service->approve($payout, $finance, null);
        $service->markPaid($payout, $finance, 'NABIL-TXN-1');
        // A stale copy of the model (a second tab) cannot pay again.
        try {
            $service->markPaid($payout, $finance, 'NABIL-TXN-2');
            $this->fail('Second payment was accepted.');
        } catch (ValidationException) {
        }
        try {
            $service->reject($payout, $finance, 'Too late to reject');
            $this->fail('Paid payout was rejected.');
        } catch (ValidationException) {
        }

        $this->assertSame(2, $payout->ledgerEntries()->count());
        $this->assertSame(200000, $this->ledgerBalance($affiliate->id, 'paid'));
    }

    public function test_one_open_payout_is_also_enforced_by_the_database(): void
    {
        $affiliate = $this->readyAffiliate();
        $this->requestPayout($affiliate)->assertCreated();
        $payout = Payout::sole();

        $this->expectException(UniqueConstraintViolationException::class);
        $copy = $payout->replicate(['public_id']);
        $copy->forceFill(['public_id' => (string) Str::ulid()])->save();
    }

    public function test_payout_amount_and_destination_are_immutable(): void
    {
        $affiliate = $this->readyAffiliate();
        $this->requestPayout($affiliate)->assertCreated();
        $payout = Payout::sole();

        $this->expectException(LogicException::class);
        $payout->forceFill(['amount_minor' => 1])->save();
    }

    public function test_affiliate_ledger_shows_payout_entries(): void
    {
        $affiliate = $this->readyAffiliate();
        $id = $this->requestPayout($affiliate)->json('data.id');

        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/earnings/ledger')->assertOk()
            ->assertJsonPath('data.0.type', 'payout_reserve')
            ->assertJsonPath('data.0.payout_id', $id)
            ->assertJsonPath('data.0.to_bucket', 'reserved');
    }

    private function ledgerBalance(int $affiliateId, string $bucket): int
    {
        return app(CommissionLedger::class)->balances($affiliateId)[$bucket];
    }
}
