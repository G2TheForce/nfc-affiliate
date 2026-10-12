<?php

namespace App\Domain\Commissions;

use App\Domain\Audit\AuditLogger;
use App\Enums\CommissionStatus;
use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use App\Models\Commission;
use App\Models\User;
use App\Notifications\Affiliate\CommissionReversed;
use App\Support\Privacy\Mask;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a commission through its lifecycle. Each move locks the row,
 * re-checks the state, and posts the matching ledger entry in the same
 * transaction:
 *
 *   pending  -> approved   holding period over, not held, order still paid
 *   approved -> available  released (automatically or by finance)
 *   pending|approved -> reversed   voided by staff with a reason
 *
 * Holds stop a commission moving forward until released.
 */
class CommissionLifecycle
{
    public function __construct(
        private readonly CommissionLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** @return bool whether the commission was approved */
    public function approve(Commission $commission): bool
    {
        return DB::transaction(function () use ($commission) {
            $locked = $this->lock($commission);

            if ($locked->status !== CommissionStatus::Pending || $locked->isHeld() || $locked->eligible_at->isFuture()
                || ! $locked->order->status->isPaid() || $locked->netMinor() <= 0) {
                return false;
            }

            $this->move($locked, LedgerEntryType::Approval, LedgerBucket::Pending, LedgerBucket::Approved, 'approve');
            $locked->forceFill(['status' => CommissionStatus::Approved, 'approved_at' => now()])->save();

            return true;
        });
    }

    /** @return bool whether the commission was released to the available balance */
    public function release(Commission $commission, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($commission, $actor) {
            $locked = $this->lock($commission);

            if ($locked->status !== CommissionStatus::Approved || $locked->isHeld() || $locked->netMinor() <= 0) {
                return false;
            }

            $this->move($locked, LedgerEntryType::Release, LedgerBucket::Approved, LedgerBucket::Available, 'release', $actor);
            $locked->forceFill(['status' => CommissionStatus::Available, 'available_at' => now()])->save();

            if ($actor) {
                $this->audit->log('commission.released', $locked, [], $actor);
            }

            return true;
        });
    }

    public function hold(Commission $commission, User $actor, string $reason): Commission
    {
        return DB::transaction(function () use ($commission, $actor, $reason) {
            $locked = $this->lock($commission);

            if (! in_array($locked->status, [CommissionStatus::Pending, CommissionStatus::Approved], true)) {
                throw ValidationException::withMessages(['status' => 'Only pending or approved commissions can be held.']);
            }
            if ($locked->isHeld()) {
                throw ValidationException::withMessages(['status' => 'This commission is already on hold.']);
            }

            $locked->forceFill(['held_at' => now(), 'hold_reason' => $reason])->save();
            $this->audit->log('commission.held', $locked, ['reason' => $reason], $actor);

            return $locked;
        });
    }

    public function releaseHold(Commission $commission, User $actor, string $note): Commission
    {
        return DB::transaction(function () use ($commission, $actor, $note) {
            $locked = $this->lock($commission);

            if (! $locked->isHeld() || $locked->status === CommissionStatus::Reversed) {
                throw ValidationException::withMessages(['status' => 'This commission is not on hold.']);
            }

            $previous = $locked->hold_reason;
            $locked->forceFill(['held_at' => null, 'hold_reason' => null])->save();
            $this->audit->log('commission.hold_released', $locked, ['note' => $note, 'hold_reason' => $previous], $actor);

            return $locked;
        });
    }

    /** Cancels a commission that should never have been earned (e.g. confirmed fraud). */
    public function void(Commission $commission, User $actor, string $reason): Commission
    {
        return DB::transaction(function () use ($commission, $actor, $reason) {
            $locked = $this->lock($commission);

            if (! in_array($locked->status, [CommissionStatus::Pending, CommissionStatus::Approved], true)) {
                throw ValidationException::withMessages(['status' => 'Only pending or approved commissions can be voided. Correct an available commission with an adjustment.']);
            }

            $net = $locked->netMinor();
            if ($net > 0) {
                $this->move($locked, LedgerEntryType::Void, $locked->status->bucket(), LedgerBucket::Reversed, 'void', $actor);
            }
            $locked->forceFill([
                'status' => CommissionStatus::Reversed,
                'reversed_minor' => $locked->amount_minor,
                'reversed_at' => now(),
                'held_at' => null,
            ])->save();
            $this->audit->log('commission.voided', $locked, ['reason' => $reason, 'amount_minor' => $net], $actor);
            if ($net > 0) {
                $locked->affiliate?->user?->notify((new CommissionReversed(
                    Mask::orderNumber($locked->order->order_number), $net, $locked->currency, CommissionReversed::CAUSE_CANCELLED,
                ))->afterCommit());
            }

            return $locked;
        });
    }

    private function lock(Commission $commission): Commission
    {
        return Commission::query()->with('order')->whereKey($commission->id)->lockForUpdate()->firstOrFail();
    }

    private function move(Commission $commission, LedgerEntryType $type, LedgerBucket $from, LedgerBucket $to, string $step, ?User $actor = null): void
    {
        $this->ledger->post(
            $commission->affiliate_profile_id, $type, $from, $to, $commission->netMinor(), $commission->currency,
            "commission:{$commission->id}:{$step}", ['commission_id' => $commission->id], $actor,
        );
    }
}
