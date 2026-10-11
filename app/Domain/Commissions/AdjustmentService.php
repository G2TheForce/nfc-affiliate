<?php

namespace App\Domain\Commissions;

use App\Domain\Audit\AuditLogger;
use App\Enums\AdjustmentStatus;
use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use App\Models\AffiliateProfile;
use App\Models\Commission;
use App\Models\CommissionAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual corrections as compensating entries (ADR 0003). One staff member
 * proposes, a different one approves; only then does the ledger move.
 * Positive amounts credit the available balance, negative ones debit it.
 */
class AdjustmentService
{
    public function __construct(
        private readonly CommissionLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function propose(AffiliateProfile $affiliate, int $amountMinor, string $reason, ?Commission $commission, User $actor): CommissionAdjustment
    {
        if ($amountMinor === 0) {
            throw ValidationException::withMessages(['amount_minor' => 'An adjustment cannot be zero.']);
        }
        if ($commission && $commission->affiliate_profile_id !== $affiliate->id) {
            throw ValidationException::withMessages(['commission_id' => 'That commission belongs to a different affiliate.']);
        }

        return DB::transaction(function () use ($affiliate, $amountMinor, $reason, $commission, $actor) {
            $adjustment = new CommissionAdjustment;
            $adjustment->forceFill([
                'affiliate_profile_id' => $affiliate->id,
                'commission_id' => $commission?->id,
                'amount_minor' => $amountMinor,
                'currency' => $commission?->currency ?? config('affiliate.currency.default'),
                'reason' => $reason,
                'status' => AdjustmentStatus::PendingApproval,
                'created_by' => $actor->id,
            ])->save();

            $this->audit->log('commission.adjustment_proposed', $adjustment, [
                'affiliate' => $affiliate->public_id,
                'commission' => $commission?->public_id,
                'amount_minor' => $amountMinor,
                'reason' => $reason,
            ], $actor);

            return $adjustment;
        });
    }

    public function approve(CommissionAdjustment $adjustment, User $actor, ?string $note): CommissionAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor, $note) {
            $locked = $this->lockPending($adjustment);

            if ($locked->created_by === $actor->id) {
                throw ValidationException::withMessages(['adjustment' => 'Someone other than the person who proposed it must approve an adjustment.']);
            }

            $amount = $locked->amount_minor;
            $this->ledger->post(
                $locked->affiliate_profile_id, LedgerEntryType::Adjustment,
                $amount < 0 ? LedgerBucket::Available : null,
                $amount > 0 ? LedgerBucket::Available : null,
                abs($amount), $locked->currency, "adjustment:{$locked->id}",
                ['commission_adjustment_id' => $locked->id, 'commission_id' => $locked->commission_id], $actor,
            );

            $locked->forceFill([
                'status' => AdjustmentStatus::Approved,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
            $this->audit->log('commission.adjustment_approved', $locked, ['amount_minor' => $amount, 'note' => $note], $actor);

            return $locked;
        });
    }

    public function reject(CommissionAdjustment $adjustment, User $actor, string $note): CommissionAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor, $note) {
            $locked = $this->lockPending($adjustment);

            $locked->forceFill([
                'status' => AdjustmentStatus::Rejected,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
            $this->audit->log('commission.adjustment_rejected', $locked, ['note' => $note], $actor);

            return $locked;
        });
    }

    private function lockPending(CommissionAdjustment $adjustment): CommissionAdjustment
    {
        $locked = CommissionAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== AdjustmentStatus::PendingApproval) {
            throw ValidationException::withMessages(['adjustment' => 'This adjustment has already been decided.']);
        }

        return $locked;
    }
}
