<?php

namespace App\Domain\Commissions;

use App\Domain\Audit\AuditLogger;
use App\Enums\AdjustmentStatus;
use App\Enums\CommissionStatus;
use App\Enums\LedgerBucket;
use App\Enums\PayoutStatus;
use App\Models\Commission;
use App\Models\CommissionAdjustment;
use App\Models\Payout;
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
            $inPaidPayout = DB::table('payout_items')
                ->join('payouts', 'payouts.id', '=', 'payout_items.payout_id')
                ->whereIn('payout_items.commission_id', $commissions->modelKeys())
                ->where('payouts.status', PayoutStatus::Paid->value)
                ->pluck('payout_items.commission_id')
                ->flip();
            foreach ($commissions as $commission) {
                $commissionsChecked++;
                $this->checkCommission($commission, $nets[$commission->id] ?? [], $inPaidPayout->has($commission->id), $add);
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

        $this->checkPayouts($add);
        $this->checkAvailableBalances($add);

        $orphans = DB::table('commission_ledger_entries')->whereNull('commission_id')->whereNull('commission_adjustment_id')->whereNull('payout_id')->count();
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
    private function checkCommission(Commission $commission, array $net, bool $inPaidPayout, callable $add): void
    {
        $id = $commission->public_id;

        if (($net['in'] ?? 0) !== $commission->amount_minor) {
            $add(['type' => 'accrual_mismatch', 'commission' => $id, 'expected_minor' => $commission->amount_minor, 'ledger_minor' => $net['in'] ?? 0]);
        }
        if (($net[LedgerBucket::Reversed->value] ?? 0) !== $commission->reversed_minor) {
            $add(['type' => 'reversal_mismatch', 'commission' => $id, 'expected_minor' => $commission->reversed_minor, 'ledger_minor' => $net[LedgerBucket::Reversed->value] ?? 0]);
        }

        // Paid only through a paid payout; a commission in a paid payout is
        // paid, or reversed when its order was refunded before payment.
        $paid = $commission->status === CommissionStatus::Paid;
        if (($paid && ! $inPaidPayout) || (! $paid && $inPaidPayout && $commission->status !== CommissionStatus::Reversed)) {
            $add(['type' => 'paid_status_mismatch', 'commission' => $id, 'status' => $commission->status->value, 'in_paid_payout' => $inPaidPayout]);
        }

        // Payouts move money at the payout level (ADR 0009), so a paid
        // commission's own entries still net into the available bucket.
        $current = match ($commission->status) {
            CommissionStatus::Reversed => null,
            CommissionStatus::Paid => LedgerBucket::Available,
            default => $commission->status->bucket(),
        };
        foreach (self::LIVE as $bucket) {
            $expected = $bucket === $current ? $commission->netMinor() : 0;
            $actual = $net[$bucket->value] ?? 0;
            if ($actual !== $expected) {
                $add(['type' => 'bucket_mismatch', 'commission' => $id, 'bucket' => $bucket->value, 'expected_minor' => $expected, 'ledger_minor' => $actual]);
            }
        }
    }

    /**
     * Each payout's entries must leave its amount reserved while open, in
     * paid once paid, and nowhere once it was rejected, failed or cancelled.
     */
    private function checkPayouts(callable $add): void
    {
        $nets = [];
        DB::table('commission_ledger_entries')->whereNotNull('payout_id')
            ->get(['payout_id', 'from_bucket', 'to_bucket', 'amount_minor'])
            ->each(function ($entry) use (&$nets) {
                foreach (['to_bucket' => 1, 'from_bucket' => -1] as $column => $sign) {
                    if ($entry->{$column}) {
                        $nets[$entry->payout_id][$entry->{$column}] = ($nets[$entry->payout_id][$entry->{$column}] ?? 0) + $sign * (int) $entry->amount_minor;
                    }
                }
            });

        Payout::query()->orderBy('id')->each(function (Payout $payout) use ($nets, $add) {
            $net = $nets[$payout->id] ?? [];
            $expected = [
                LedgerBucket::Reserved->value => $payout->status->isOpen() ? $payout->amount_minor : 0,
                LedgerBucket::Paid->value => $payout->status === PayoutStatus::Paid ? $payout->amount_minor : 0,
                LedgerBucket::Available->value => $payout->status === PayoutStatus::Paid || $payout->status->isOpen() ? -$payout->amount_minor : 0,
            ];
            foreach ($expected as $bucket => $amount) {
                if (($net[$bucket] ?? 0) !== $amount) {
                    $add(['type' => 'payout_mismatch', 'payout' => $payout->public_id, 'bucket' => $bucket, 'expected_minor' => $amount, 'ledger_minor' => $net[$bucket] ?? 0]);
                }
            }
            if (($payout->open_affiliate_profile_id !== null) !== $payout->status->isOpen()) {
                $add(['type' => 'payout_open_flag', 'payout' => $payout->public_id, 'status' => $payout->status->value]);
            }
        });
    }

    /**
     * Per affiliate, the available balance re-derived from records: what
     * available and paid commissions are still worth, plus approved
     * adjustments, minus payouts that are open or paid.
     */
    private function checkAvailableBalances(callable $add): void
    {
        $derived = [];
        $bump = function (int $affiliateId, int $amount) use (&$derived) {
            $derived[$affiliateId] = ($derived[$affiliateId] ?? 0) + $amount;
        };

        DB::table('commissions')->whereIn('status', [CommissionStatus::Available->value, CommissionStatus::Paid->value])
            ->groupBy('affiliate_profile_id')
            ->selectRaw('affiliate_profile_id, SUM(amount_minor - reversed_minor) as total')
            ->get()->each(fn ($row) => $bump((int) $row->affiliate_profile_id, (int) $row->total));
        DB::table('commission_adjustments')->where('status', AdjustmentStatus::Approved->value)
            ->groupBy('affiliate_profile_id')
            ->selectRaw('affiliate_profile_id, SUM(amount_minor) as total')
            ->get()->each(fn ($row) => $bump((int) $row->affiliate_profile_id, (int) $row->total));
        DB::table('payouts')->whereIn('status', [PayoutStatus::Requested->value, PayoutStatus::Approved->value, PayoutStatus::Paid->value])
            ->groupBy('affiliate_profile_id')
            ->selectRaw('affiliate_profile_id, SUM(amount_minor) as total')
            ->get()->each(fn ($row) => $bump((int) $row->affiliate_profile_id, -(int) $row->total));

        $in = DB::table('commission_ledger_entries')->where('to_bucket', LedgerBucket::Available->value)
            ->groupBy('affiliate_profile_id')->selectRaw('affiliate_profile_id, SUM(amount_minor) as total')->pluck('total', 'affiliate_profile_id');
        $out = DB::table('commission_ledger_entries')->where('from_bucket', LedgerBucket::Available->value)
            ->groupBy('affiliate_profile_id')->selectRaw('affiliate_profile_id, SUM(amount_minor) as total')->pluck('total', 'affiliate_profile_id');

        $affiliates = collect(array_keys($derived))->merge($in->keys())->merge($out->keys())->unique();
        $publicIds = DB::table('affiliate_profiles')->whereIn('id', $affiliates)->pluck('public_id', 'id');
        foreach ($affiliates as $affiliateId) {
            $ledger = (int) ($in[$affiliateId] ?? 0) - (int) ($out[$affiliateId] ?? 0);
            $expected = $derived[$affiliateId] ?? 0;
            if ($ledger !== $expected) {
                $add(['type' => 'available_balance_mismatch', 'affiliate' => $publicIds[$affiliateId] ?? null, 'expected_minor' => $expected, 'ledger_minor' => $ledger]);
            }
        }
    }
}
