<?php

namespace Tests\Feature\Payouts;

use App\Domain\Commissions\CommissionLedger;
use App\Domain\Commissions\ReconciliationService;
use App\Enums\AffiliateStatus;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionLedgerEntry;
use App\Models\Payout;
use App\Notifications\Payouts\NewPayoutRequest;
use App\Notifications\Payouts\PayoutStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PayoutFlowTest extends TestCase
{
    use PayoutTestHelpers;
    use RefreshDatabase;

    public function test_full_flow_request_approve_pay(): void
    {
        Notification::fake();
        $affiliate = $this->readyAffiliate();
        $finance = $this->finance();

        $id = $this->requestPayout($affiliate)->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.amount_minor', 200000)
            ->assertJsonPath('data.destination', 'Nabil Bank ••••1234')
            ->assertJsonPath('data.commission_count', 1)
            ->json('data.id');
        $this->assertBalances($affiliate->id, available: 0);
        $this->assertSame(200000, $this->balance($affiliate, 'reserved'));
        Notification::assertSentTo($affiliate->user, PayoutStatusChanged::class, fn ($n) => $n->status === 'requested');
        Notification::assertSentTo($finance, NewPayoutRequest::class);

        $this->approve($id, $finance)->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.reviewed_by', $finance->name);
        $this->approve($id, $finance)->assertUnprocessable()->assertJsonValidationErrors('payout');

        $this->markPaid($id, 'NABIL-TXN-0001', $finance)->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_reference', 'NABIL-TXN-0001');
        $this->markPaid($id, 'NABIL-TXN-0002', $finance)->assertUnprocessable()
            ->assertJsonPath('errors.payout.0', 'This payout is already marked as paid.');

        $this->assertSame(0, $this->balance($affiliate, 'reserved'));
        $this->assertSame(200000, $this->balance($affiliate, 'paid'));
        $commission = Commission::sole();
        $this->assertSame('paid', $commission->status->value);
        $this->assertNotNull($commission->paid_at);
        Notification::assertSentTo($affiliate->user, PayoutStatusChanged::class, fn ($n) => $n->status === 'paid' && $n->reference === 'NABIL-TXN-0001');

        $this->assertSame(
            ['payout.requested', 'payout.approved', 'payout.paid'],
            AuditLog::query()->where('subject_type', 'Payout')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame('clean', app(ReconciliationService::class)->run()->result);
    }

    public function test_reject_returns_the_funds(): void
    {
        Notification::fake();
        $affiliate = $this->readyAffiliate();
        $id = $this->requestPayout($affiliate)->json('data.id');

        $this->actingAs($this->finance())->postJson("/api/v1/admin/payouts/{$id}/reject", ['note' => 'no'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($this->finance())->postJson("/api/v1/admin/payouts/{$id}/reject", ['note' => 'Account name does not match the affiliate'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertBalances($affiliate->id, available: 200000);
        $this->assertSame(0, $this->balance($affiliate, 'reserved'));
        $this->assertSame('available', Commission::sole()->status->value);
        Notification::assertSentTo($affiliate->user, PayoutStatusChanged::class, fn ($n) => $n->status === 'rejected' && $n->reason === 'Account name does not match the affiliate');
        $this->assertSame('clean', app(ReconciliationService::class)->run()->result);

        // The affiliate can ask again, and the same commission is covered again.
        $this->requestPayout($affiliate)->assertCreated()->assertJsonPath('data.commission_count', 1);
    }

    public function test_failed_transfer_returns_the_funds(): void
    {
        $affiliate = $this->readyAffiliate();
        $id = $this->requestPayout($affiliate)->json('data.id');

        $this->actingAs($this->finance())->postJson("/api/v1/admin/payouts/{$id}/mark-failed", ['reason' => 'Bank returned the transfer'])
            ->assertUnprocessable()->assertJsonValidationErrors('payout');
        $this->approve($id)->assertOk();
        $this->actingAs($this->finance())->postJson("/api/v1/admin/payouts/{$id}/mark-failed", ['reason' => 'Bank returned the transfer'])
            ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_reason', 'Bank returned the transfer');
        $this->markPaid($id)->assertUnprocessable();

        $this->assertBalances($affiliate->id, available: 200000);
        $this->assertSame('clean', app(ReconciliationService::class)->run()->result);
    }

    public function test_affiliate_can_cancel_before_review_only(): void
    {
        $affiliate = $this->readyAffiliate();
        $id = $this->requestPayout($affiliate)->json('data.id');

        $other = $this->readyAffiliate();
        $this->actingAs($other->user)->postJson("/api/v1/affiliate/payouts/{$id}/cancel")->assertNotFound();

        $this->actingAs($affiliate->user)->postJson("/api/v1/affiliate/payouts/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertBalances($affiliate->id, available: 200000);

        $second = $this->requestPayout($affiliate)->json('data.id');
        $this->approve($second)->assertOk();
        $this->actingAs($affiliate->user)->postJson("/api/v1/affiliate/payouts/{$second}/cancel")->assertUnprocessable();
    }

    public function test_request_eligibility(): void
    {
        $affiliate = $this->approvedAffiliate();

        // Nothing earned and no payout details.
        $this->requestPayout($affiliate)->assertUnprocessable()->assertJsonPath('errors.payout.0', 'Add your payout details before requesting a payout.');
        $this->saveBankMethod($affiliate)->assertOk();
        $this->requestPayout($affiliate)->assertUnprocessable()
            ->assertJsonPath('errors.payout.0', 'Your available balance is NPR 0.00. The minimum payout is NPR 1,000.00.');

        // NPR 900 earned: below the NPR 1,000 minimum.
        $this->earn($affiliate, 900000);
        $this->requestPayout($affiliate)->assertUnprocessable();
        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/payouts/summary')->assertOk()
            ->assertJsonPath('data.available_minor', 90000)
            ->assertJsonPath('data.can_request', false)
            ->assertJsonPath('data.blocker', 'Your available balance is below the minimum payout.');

        // Pending commission does not count.
        $this->app['auth']->forgetGuards();
        $order = $this->attributedOrder($affiliate, $this->product(['price_minor' => 500000]), 1);
        $this->pay($order)->assertCreated();
        $this->requestPayout($affiliate)->assertUnprocessable();

        $this->earn($affiliate, 100000);
        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/payouts/summary')->assertJsonPath('data.can_request', true);
        // The earlier pending order matured with the travel too: 900 + 500 + 100.
        $this->requestPayout($affiliate)->assertCreated()->assertJsonPath('data.amount_minor', 150000)->assertJsonPath('data.commission_count', 3);

        // One open payout at a time.
        $this->requestPayout($affiliate)->assertUnprocessable()->assertJsonPath('errors.payout.0', 'You already have a payout request in progress.');
        $this->assertSame(1, Payout::count());
    }

    public function test_suspended_affiliate_cannot_request_and_cannot_be_paid(): void
    {
        $affiliate = $this->readyAffiliate();
        $id = $this->requestPayout($affiliate)->json('data.id');
        $affiliate->forceFill(['status' => AffiliateStatus::Suspended])->save();

        $this->approve($id)->assertUnprocessable()->assertJsonValidationErrors('payout');
        $this->actingAs($this->finance())->postJson("/api/v1/admin/payouts/{$id}/reject", ['note' => 'Membership suspended for review'])->assertOk();

        $this->requestPayout($affiliate)->assertForbidden();
    }

    public function test_reference_is_unique_per_method(): void
    {
        $first = $this->readyAffiliate();
        $second = $this->readyAffiliate();
        $a = $this->requestPayout($first)->json('data.id');
        $b = $this->requestPayout($second)->json('data.id');
        $this->approve($a)->assertOk();
        $this->approve($b)->assertOk();

        $this->markPaid($a, 'NABIL-TXN-7777')->assertOk();
        $this->markPaid($b, 'NABIL-TXN-7777')->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->markPaid($b, 'bad ref!')->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->markPaid($b, 'NABIL-TXN-7778')->assertOk();
    }

    public function test_separate_payer_can_be_required(): void
    {
        config(['affiliate.payouts.require_separate_payer' => true]);
        $affiliate = $this->readyAffiliate();
        $id = $this->requestPayout($affiliate)->json('data.id');
        $approver = $this->finance();
        $this->approve($id, $approver)->assertOk();

        $this->actingAs($approver)->getJson("/api/v1/admin/payouts/{$id}")->assertJsonPath('data.can_mark_paid', false);
        $this->markPaid($id, 'NABIL-TXN-0001', $approver)->assertUnprocessable()->assertJsonValidationErrors('payout');
        $this->markPaid($id, 'NABIL-TXN-0001', $this->finance())->assertOk();
    }

    public function test_refund_while_payout_is_open_claws_back_from_available(): void
    {
        $affiliate = $this->approvedAffiliate();
        $this->earn($affiliate, 1500000);
        $this->earn($affiliate, 1000000);
        $this->saveBankMethod($affiliate)->assertOk();
        $id = $this->requestPayout($affiliate)->assertJsonPath('data.amount_minor', 250000)->json('data.id');

        // Refund the second order in full while the payout is open.
        $commission = Commission::query()->latest('id')->first();
        $order = $commission->order;
        $this->refund($order, [['item_id' => $order->items()->value('public_id'), 'amount_minor' => 1000000]])->assertCreated();

        $this->assertSame('reversed', $commission->fresh()->status->value);
        $this->assertBalances($affiliate->id, available: -100000);

        $this->approve($id)->assertOk();
        $this->markPaid($id)->assertOk();
        $this->assertSame(['paid', 'reversed'], Commission::query()->orderBy('id')->get()->map(fn ($c) => $c->status->value)->all());
        $this->assertBalances($affiliate->id, available: -100000);
        $run = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $run->result, json_encode($run->issues));

        // The shortfall is recovered from later earnings before the next payout.
        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/payouts/summary')->assertJsonPath('data.available_minor', -100000);
    }

    public function test_refund_after_payment_keeps_the_commission_paid_and_reconciles(): void
    {
        $affiliate = $this->readyAffiliate();
        $id = $this->requestPayout($affiliate)->json('data.id');
        $this->approve($id)->assertOk();
        $this->markPaid($id)->assertOk();

        $order = Commission::sole()->order;
        $this->refund($order, [['item_id' => $order->items()->value('public_id'), 'amount_minor' => 1000000]])->assertCreated();

        $this->assertSame('paid', Commission::sole()->status->value);
        $this->assertBalances($affiliate->id, available: -100000);
        $this->assertSame('clean', app(ReconciliationService::class)->run()->result);
    }

    public function test_adjustments_are_part_of_the_payout(): void
    {
        $affiliate = $this->readyAffiliate();
        $maker = $this->finance();
        $adj = $this->actingAs($maker)->postJson('/api/v1/admin/adjustments', [
            'affiliate_id' => $affiliate->public_id, 'amount_minor' => 30000, 'reason' => 'Bonus for the Tihar campaign',
        ])->json('data.id');
        $this->actingAs($this->finance())->postJson("/api/v1/admin/adjustments/{$adj}/approve")->assertOk();

        $id = $this->requestPayout($affiliate)->assertJsonPath('data.amount_minor', 230000)->json('data.id');
        $this->approve($id)->assertOk();
        $this->markPaid($id)->assertOk();

        $this->assertBalances($affiliate->id, available: 0);
        $this->assertSame('clean', app(ReconciliationService::class)->run()->result);
    }

    public function test_reconciliation_flags_a_payout_entry_that_does_not_match(): void
    {
        $affiliate = $this->readyAffiliate();
        $this->requestPayout($affiliate)->assertCreated();
        $payout = Payout::sole();

        // Simulate drift: a second reserve entry nobody should have written.
        CommissionLedgerEntry::query()->insert([
            'affiliate_profile_id' => $affiliate->id, 'payout_id' => $payout->id, 'type' => 'payout_reserve',
            'from_bucket' => 'available', 'to_bucket' => 'reserved', 'amount_minor' => 500, 'currency' => 'NPR',
            'idempotency_key' => 'drift-test', 'created_at' => now(),
        ]);

        $run = app(ReconciliationService::class)->run();
        $this->assertSame('drift', $run->result);
        $types = collect($run->issues)->pluck('type')->unique()->values()->all();
        $this->assertContains('payout_mismatch', $types);
        $this->assertContains('available_balance_mismatch', $types);
    }

    private function balance($affiliate, string $bucket): int
    {
        return app(CommissionLedger::class)->balances($affiliate->id)[$bucket];
    }
}
