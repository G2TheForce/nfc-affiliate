<?php

namespace App\Domain\Commissions;

use App\Domain\Audit\AuditLogger;
use App\Enums\AdjustmentStatus;
use App\Enums\CommissionStatus;
use App\Enums\LedgerBucket;
use App\Models\Commission;
use App\Models\CommissionAdjustment;
use App\Models\ReconciliationRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-derives what the ledger should hold from the commission and adjustment
 * records and compares (ADR 0003). Any difference is recorded as drift for
 * finance to investigate; nothing is corrected automatically.
 */
class ReconciliationService
{
    private const MAX_ISSUES = 200;

    private const LIVE = [LedgerBucket::Pending, LedgerBucket::Approved, LedgerBucket::Available];

    public function __construct(private readonly AuditLogger $audit) {}

    public function run(?User $actor = null): ReconciliationRun
    {
        $startedAt = now();
        $issues = [];
        $add = function (array $issue) use (&$issues) {
            if (count($issues) < self::MAX_ISSUES) {
                $issues[] = $issue;
            }
        };

        $commissionsChecked = 0;
        Commission::query()->orderBy('id')->chunkById(500, function ($commissions) use (&$commissionsChecked, $add) {
            $nets = $this->netsByCommission($commissions->modelKeys());
            foreach ($commissions as $commission) {
                $commissionsChecked++;
                $this->checkCommission($commission, $nets[$commission->id] ?? [], $add);
            }
        });

        $adjustmentEntries = DB::table('commission_ledger_entries')
            ->whereNotNull('commission_adjustment_id')
            ->get(['commission_adjustment_id', 'from_bucket', 'to_bucket', 'amount_minor'])
            ->groupBy('commission_adjustment_id');
        CommissionAdjustment::query()->orderBy('id')->each(function (CommissionAdjustment $adjustment) use ($adjustmentEntries, $add) {
            $entries = $adjustmentEntries->get($adjustment->id, collect());
            $signed = $entries->sum(fn ($e) => ($e->to_bucket === LedgerBucket::Available->value ? 1 : -1) * (int) $e->amount_minor);
            $expected = $adjustment->status === AdjustmentStatus::Approved ? $adjustment->amount_minor : 0;
            if ($signed !== $expected || ($expected !== 0 && $entries->count() !== 1)) {
                $add(['type' => 'adjustment_mismatch', 'adjustment' => $adjustment->public_id, 'expected_minor' => $expected, 'ledger_minor' => $signed]);
            }
        });

        $orphans = DB::table('commission_ledger_entries')->whereNull('commission_id')->whereNull('commission_adjustment_id')->count();
        if ($orphans > 0) {
            $add(['type' => 'unlinked_entries', 'count' => $orphans]);
        }

        $run = new ReconciliationRun;
        $run->forceFill([
            'result' => $issues === [] ? 'clean' : 'drift',
            'commissions_checked' => $commissionsChecked,
            'affiliates_checked' => DB::table('commission_ledger_entries')->distinct()->count('affiliate_profile_id'),
            'entries_checked' => DB::table('commission_ledger_entries')->count(),
            'issues' => $issues,
            'triggered_by' => $actor?->id,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ])->save();

        if ($issues !== []) {
            Log::error('Commission ledger reconciliation found drift.', ['run' => $run->public_id, 'issues' => count($issues)]);
            $this->audit->log('reconciliation.drift', $run, ['issues' => count($issues)], $actor);
        }

        return $run;
    }

    /**
     * Net amount per bucket for each commission, from its ledger entries.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function netsByCommission(array $ids): array
    {
        $nets = [];
        DB::table('commission_ledger_entries')
            ->whereIn('commission_id', $ids)
            ->whereNull('commission_adjustment_id')
            ->get(['commission_id', 'from_bucket', 'to_bucket', 'amount_minor'])
            ->each(function ($entry) use (&$nets) {
                $amount = (int) $entry->amount_minor;
                $nets[$entry->commission_id]['in'] = ($nets[$entry->commission_id]['in'] ?? 0) + ($entry->from_bucket === null ? $amount : 0);
                if ($entry->to_bucket) {
                    $nets[$entry->commission_id][$entry->to_bucket] = ($nets[$entry->commission_id][$entry->to_bucket] ?? 0) + $amount;
                }
                if ($entry->from_bucket) {
                    $nets[$entry->commission_id][$entry->from_bucket] = ($nets[$entry->commission_id][$entry->from_bucket] ?? 0) - $amount;
                }
            });

        return $nets;
    }

    /**
     * @param  array<string, int>  $net
     */
    private function checkCommission(Commission $commission, array $net, callable $add): void
    {
        $id = $commission->public_id;

        if (($net['in'] ?? 0) !== $commission->amount_minor) {
            $add(['type' => 'accrual_mismatch', 'commission' => $id, 'expected_minor' => $commission->amount_minor, 'ledger_minor' => $net['in'] ?? 0]);
        }
        if (($net[LedgerBucket::Reversed->value] ?? 0) !== $commission->reversed_minor) {
            $add(['type' => 'reversal_mismatch', 'commission' => $id, 'expected_minor' => $commission->reversed_minor, 'ledger_minor' => $net[LedgerBucket::Reversed->value] ?? 0]);
        }

        // Paid commissions are checked against payouts once those exist (Phase 5).
        if ($commission->status === CommissionStatus::Paid) {
            return;
        }

        $current = $commission->status === CommissionStatus::Reversed ? null : $commission->status->bucket();
        foreach (self::LIVE as $bucket) {
            $expected = $bucket === $current ? $commission->netMinor() : 0;
            $actual = $net[$bucket->value] ?? 0;
            if ($actual !== $expected) {
                $add(['type' => 'bucket_mismatch', 'commission' => $id, 'bucket' => $bucket->value, 'expected_minor' => $expected, 'ledger_minor' => $actual]);
            }
        }
    }
}
