<?php

namespace Tests\Feature\Commissions;

use App\Enums\Role;
use App\Models\Commission;
use App\Models\CommissionAdjustment;
use App\Models\CommissionLedgerEntry;
use App\Models\ReconciliationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AdjustmentAndReconciliationTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;
    use RefundDatabaseHelpers;

    public function test_adjustment_needs_a_second_person_to_approve(): void
    {
        $affiliate = $this->approvedAffiliate();
        $maker = $this->finance();
        $checker = $this->finance();

        $id = $this->actingAs($maker)->postJson('/api/v1/admin/adjustments', [
            'affiliate_id' => $affiliate->public_id,
            'amount_minor' => 50000,
            'reason' => 'Bonus agreed for the Dashain campaign',
        ])->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data.id');
        $this->assertSame(0, CommissionLedgerEntry::count());

        $this->actingAs($maker)->postJson("/api/v1/admin/adjustments/{$id}/approve")->assertUnprocessable()->assertJsonValidationErrors('adjustment');
        $this->actingAs($checker)->postJson("/api/v1/admin/adjustments/{$id}/approve", ['note' => 'Matches the agreement'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by', $checker->name);
        $this->actingAs($checker)->postJson("/api/v1/admin/adjustments/{$id}/approve")->assertUnprocessable();

        $this->assertBalances($affiliate->id, available: 50000);
        $entry = CommissionLedgerEntry::sole();
        $this->assertSame('adjustment', $entry->type->value);
        $this->assertSame($checker->id, $entry->actor_id);
    }

    public function test_negative_adjustment_debits_the_available_balance(): void
    {
        $affiliate = $this->approvedAffiliate();
        $adjustment = $this->proposeAndApprove($affiliate->public_id, -12345);

        $this->assertSame('approved', $adjustment['status']);
        $this->assertBalances($affiliate->id, available: -12345);
    }

    public function test_rejected_adjustment_never_reaches_the_ledger(): void
    {
        $affiliate = $this->approvedAffiliate();
        $id = $this->actingAs($this->finance())->postJson('/api/v1/admin/adjustments', [
            'affiliate_id' => $affiliate->public_id, 'amount_minor' => 1000, 'reason' => 'Duplicate request by mistake',
        ])->json('data.id');

        $this->actingAs($this->finance())->postJson("/api/v1/admin/adjustments/{$id}/reject", ['note' => 'Not agreed'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(0, CommissionLedgerEntry::count());
    }

    public function test_adjustment_rules(): void
    {
        $affiliate = $this->approvedAffiliate();
        $finance = $this->finance();

        $this->actingAs($finance)->postJson('/api/v1/admin/adjustments', ['affiliate_id' => $affiliate->public_id, 'amount_minor' => 0, 'reason' => 'Zero is not an adjustment'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->actingAs($finance)->postJson('/api/v1/admin/adjustments', ['affiliate_id' => $affiliate->public_id, 'amount_minor' => 100, 'reason' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->paidOrder(100000, 1);
        $other = Commission::sole();
        $this->actingAs($finance)->postJson('/api/v1/admin/adjustments', ['affiliate_id' => $affiliate->public_id, 'commission_id' => $other->public_id, 'amount_minor' => 100, 'reason' => 'Linked to the wrong affiliate'])
            ->assertUnprocessable()->assertJsonValidationErrors('commission_id');

        foreach ([Role::AffiliateManager, Role::Support] as $role) {
            $this->actingAs($this->userWithRole($role))->postJson('/api/v1/admin/adjustments', ['affiliate_id' => $affiliate->public_id, 'amount_minor' => 100, 'reason' => 'Not allowed to do this'])->assertForbidden();
        }
    }

    public function test_adjustments_cannot_be_edited_or_deleted(): void
    {
        $affiliate = $this->approvedAffiliate();
        $this->proposeAndApprove($affiliate->public_id, 1000);
        $adjustment = CommissionAdjustment::sole();

        $this->expectException(LogicException::class);
        $adjustment->forceFill(['amount_minor' => 999999])->save();
    }

    public function test_ledger_entries_are_immutable(): void
    {
        $this->paidOrder(100000, 1);
        $entry = CommissionLedgerEntry::sole();

        try {
            $entry->forceFill(['amount_minor' => 1])->save();
            $this->fail('Ledger entry was updated.');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_commission_calculation_fields_are_frozen(): void
    {
        $this->paidOrder(100000, 1);

        $this->expectException(LogicException::class);
        Commission::sole()->forceFill(['amount_minor' => 99999999])->save();
    }

    public function test_reconciliation_is_clean_after_normal_activity(): void
    {
        [$order, $item] = $this->paidOrder(100000, 2);
        $this->refund($order, [['item_id' => $item, 'amount_minor' => 30000]])->assertCreated();
        $this->proposeAndApprove(Commission::sole()->affiliate->public_id, 2500);
        $this->travel(15)->days();
        $this->artisan('affiliate:mature-commissions');

        $this->artisan('affiliate:reconcile-commissions')->expectsOutputToContain('clean')->assertSuccessful();

        $run = ReconciliationRun::sole();
        $this->assertSame('clean', $run->result);
        $this->assertSame(1, $run->commissions_checked);
        $this->assertSame([], $run->issues);
    }

    public function test_reconciliation_flags_a_ledger_that_does_not_match_the_records(): void
    {
        $this->paidOrder(100000, 1);
        $commission = Commission::sole();
        // Simulate tampering outside the application: a raw insert into the ledger.
        DB::table('commission_ledger_entries')->insert([
            'affiliate_profile_id' => $commission->affiliate_profile_id,
            'commission_id' => $commission->id,
            'type' => 'release',
            'from_bucket' => 'pending',
            'to_bucket' => 'available',
            'amount_minor' => 10000,
            'currency' => 'NPR',
            'idempotency_key' => 'tampered',
            'created_at' => now(),
        ]);

        $this->artisan('affiliate:reconcile-commissions')->expectsOutputToContain('drift')->assertFailed();

        $issues = collect(ReconciliationRun::sole()->issues);
        $this->assertTrue($issues->contains(fn ($i) => $i['type'] === 'bucket_mismatch' && $i['bucket'] === 'available'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'reconciliation.drift']);
    }

    public function test_finance_can_run_and_list_reconciliation(): void
    {
        $finance = $this->finance();

        $this->actingAs($finance)->postJson('/api/v1/admin/reconciliation')->assertCreated()->assertJsonPath('data.result', 'clean');
        $this->actingAs($finance)->getJson('/api/v1/admin/reconciliation')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->userWithRole(Role::AffiliateManager))->getJson('/api/v1/admin/reconciliation')->assertForbidden();
    }

    private function proposeAndApprove(string $affiliateId, int $amount): array
    {
        $id = $this->actingAs($this->finance())->postJson('/api/v1/admin/adjustments', [
            'affiliate_id' => $affiliateId, 'amount_minor' => $amount, 'reason' => 'Correction agreed with the affiliate',
        ])->assertCreated()->json('data.id');

        return $this->actingAs($this->finance())->postJson("/api/v1/admin/adjustments/{$id}/approve")->assertOk()->json('data');
    }
}
