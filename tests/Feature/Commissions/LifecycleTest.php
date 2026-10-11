<?php

namespace Tests\Feature\Commissions;

use App\Enums\Role;
use App\Models\Commission;
use App\Models\CommissionLedgerEntry;
use App\Notifications\Affiliate\CommissionsAvailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LifecycleTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;
    use RefundDatabaseHelpers;

    public function test_nothing_matures_inside_the_holding_period(): void
    {
        $this->paidOrder(100000, 1);
        $this->travel(13)->days();

        $this->artisan('affiliate:mature-commissions')->expectsOutputToContain('Approved 0')->assertSuccessful();

        $this->assertSame('pending', Commission::sole()->status->value);
    }

    public function test_after_the_holding_period_commission_becomes_available_and_the_affiliate_is_told(): void
    {
        Notification::fake();
        $this->paidOrder(100000, 2);
        $commission = Commission::sole();
        $this->travelTo(now()->addDays(14)->addMinute());

        $this->artisan('affiliate:mature-commissions')->expectsOutputToContain('Approved 1 and released 1')->assertSuccessful();

        $commission->refresh();
        $this->assertSame('available', $commission->status->value);
        $this->assertNotNull($commission->approved_at);
        $this->assertNotNull($commission->available_at);
        $this->assertBalances($commission->affiliate_profile_id, pending: 0, approved: 0, available: 20000);
        Notification::assertSentTo($commission->affiliate->user, CommissionsAvailable::class, fn ($n) => $n->amountMinor === 20000);

        // Running again changes nothing.
        $this->artisan('affiliate:mature-commissions')->expectsOutputToContain('Approved 0')->assertSuccessful();
        $this->assertSame(3, CommissionLedgerEntry::count());
    }

    public function test_with_auto_release_off_finance_releases_approved_commissions(): void
    {
        config(['affiliate.commissions.auto_release' => false]);
        $this->paidOrder(100000, 1);
        $this->travel(15)->days();
        $this->artisan('affiliate:mature-commissions')->assertSuccessful();
        $commission = Commission::sole();
        $this->assertSame('approved', $commission->status->value);

        $this->actingAs($this->userWithRole(Role::AffiliateManager))->postJson("/api/v1/admin/commissions/{$commission->public_id}/release")->assertForbidden();
        $this->actingAs($this->finance())->postJson("/api/v1/admin/commissions/{$commission->public_id}/release")
            ->assertOk()->assertJsonPath('data.status', 'available');
        $this->actingAs($this->finance())->postJson("/api/v1/admin/commissions/{$commission->public_id}/release")->assertUnprocessable();
    }

    public function test_held_commission_does_not_mature_until_the_hold_is_released(): void
    {
        $this->paidOrder(100000, 1);
        $commission = Commission::sole();
        $reviewer = $this->userWithRole(Role::AffiliateManager);

        $this->actingAs($reviewer)->postJson("/api/v1/admin/commissions/{$commission->public_id}/hold", ['reason' => 'Many orders from one address'])
            ->assertOk()->assertJsonPath('data.on_hold', true)->assertJsonPath('data.hold_reason', 'Many orders from one address');
        $this->travel(20)->days();
        $this->artisan('affiliate:mature-commissions')->assertSuccessful();
        $this->assertSame('pending', $commission->fresh()->status->value);

        $this->actingAs($reviewer)->postJson("/api/v1/admin/commissions/{$commission->public_id}/release-hold", ['note' => 'Checked, genuine customer'])->assertOk();
        $this->artisan('affiliate:mature-commissions')->assertSuccessful();
        $this->assertSame('available', $commission->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'commission.held', 'actor_id' => $reviewer->id]);
    }

    public function test_void_moves_the_commission_to_reversed(): void
    {
        $this->paidOrder(100000, 1);
        $commission = Commission::sole();

        $this->actingAs($this->userWithRole(Role::AffiliateManager))
            ->postJson("/api/v1/admin/commissions/{$commission->public_id}/void", ['reason' => 'Customer is the affiliate\'s own second account'])
            ->assertOk()->assertJsonPath('data.status', 'reversed')->assertJsonPath('data.net_minor', 0);

        $this->assertBalances($commission->affiliate_profile_id, pending: 0, reversed: 10000);
    }

    public function test_available_commission_cannot_be_voided(): void
    {
        $this->paidOrder(100000, 1);
        $this->travel(15)->days();
        $this->artisan('affiliate:mature-commissions');

        $this->actingAs($this->userWithRole(Role::SuperAdmin))
            ->postJson('/api/v1/admin/commissions/'.Commission::sole()->public_id.'/void', ['reason' => 'Trying to void late'])
            ->assertUnprocessable();
    }

    public function test_finance_and_support_cannot_hold_or_void(): void
    {
        $this->paidOrder(100000, 1);
        $id = Commission::sole()->public_id;

        foreach ([Role::FinanceManager, Role::Support, Role::Affiliate] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->postJson("/api/v1/admin/commissions/{$id}/hold", ['reason' => 'Looks suspicious'])->assertForbidden();
            $this->actingAs($user)->postJson("/api/v1/admin/commissions/{$id}/void", ['reason' => 'Looks suspicious to me'])->assertForbidden();
        }
    }

    public function test_partly_refunded_commission_matures_with_its_net_amount(): void
    {
        [$order, $item] = $this->paidOrder(100000, 2);
        $this->refund($order, [['item_id' => $item, 'amount_minor' => 50000]])->assertCreated();
        $this->travel(15)->days();

        $this->artisan('affiliate:mature-commissions')->assertSuccessful();

        $this->assertBalances(Commission::sole()->affiliate_profile_id, pending: 0, available: 15000, reversed: 5000);
    }
}
