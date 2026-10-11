<?php

namespace App\Domain\Payouts;

use App\Domain\Audit\AuditLogger;
use App\Domain\Commissions\CommissionLedger;
use App\Enums\CommissionStatus;
use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Enums\Permission;
use App\Models\AffiliateProfile;
use App\Models\Commission;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\User;
use App\Notifications\Payouts\NewPayoutRequest;
use App\Notifications\Payouts\PayoutStatusChanged;
use App\Support\Money;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The payout lifecycle (ADR 0009). A request reserves the affiliate's whole
 * available balance; finance approves it, makes the transfer outside the
 * system and confirms it with the transfer reference. Rejected, failed and
 * cancelled payouts return the reserved funds. Every step locks the payout
 * row, checks its current state and posts an idempotent ledger entry, so a
 * double click or a retried request cannot approve or pay twice.
 */
class PayoutService
{
    public function __construct(
        private readonly CommissionLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function request(AffiliateProfile $affiliate, User $actor): Payout
    {
        $payout = DB::transaction(function () use ($affiliate, $actor) {
            // Serialises requests from the same affiliate (two tabs, a retry).
            $locked = AffiliateProfile::query()->whereKey($affiliate->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isEligibleToEarn()) {
                throw ValidationException::withMessages(['payout' => 'Payouts can be requested while your affiliate membership is active.']);
            }
            $method = $locked->payoutMethod()->first();
            if (! $method) {
                throw ValidationException::withMessages(['payout' => 'Add your payout details before requesting a payout.']);
            }
            if (Payout::query()->where('affiliate_profile_id', $locked->id)->whereIn('status', self::openStatuses())->exists()) {
                throw ValidationException::withMessages(['payout' => 'You already have a payout request in progress.']);
            }

            $currency = (string) config('affiliate.currency.default');
            $available = $this->ledger->balances($locked->id)[LedgerBucket::Available->value];
            $minimum = (int) config('affiliate.payouts.minimum_minor');
            if ($available < $minimum) {
                throw ValidationException::withMessages(['payout' => 'Your available balance is '.Money::format($available, $currency)
                    .'. The minimum payout is '.Money::format($minimum, $currency).'.']);
            }

            $payout = new Payout;
            $payout->forceFill([
                'affiliate_profile_id' => $locked->id,
                'open_affiliate_profile_id' => $locked->id,
                'amount_minor' => $available,
                'currency' => $currency,
                'status' => PayoutStatus::Requested,
                'method_type' => $method->type,
                'account_name' => $method->account_name,
                'destination' => $method->details,
                'destination_masked' => $method->masked,
                'requested_at' => now(),
            ])->save();

            $commissions = Commission::query()
                ->where('affiliate_profile_id', $locked->id)
                ->where('status', CommissionStatus::Available->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $items = $commissions->filter(fn (Commission $c) => $c->netMinor() > 0)
                ->map(fn (Commission $c) => ['payout_id' => $payout->id, 'commission_id' => $c->id, 'amount_minor' => $c->netMinor()])
                ->values()->all();
            if ($items !== []) {
                PayoutItem::query()->insert($items);
            }

            $this->ledger->post(
                $locked->id, LedgerEntryType::PayoutReserve, LedgerBucket::Available, LedgerBucket::Reserved,
                $available, $currency, "payout:{$payout->id}:reserve", ['payout_id' => $payout->id], $actor,
            );

            $this->audit->log('payout.requested', $payout, [
                'affiliate' => $locked->public_id,
                'amount_minor' => $available,
                'commissions' => count($items),
                'destination' => $method->masked,
            ], $actor);

            $locked->user->notify((new PayoutStatusChanged($payout))->afterCommit());
            $reviewers = User::permission(Permission::PayoutsReview->value)->where('status', 'active')->get();
            Notification::send($reviewers, (new NewPayoutRequest($payout, $locked->display_name))->afterCommit());

            return $payout;
        });

        return $payout;
    }

    public function cancel(Payout $payout, User $actor): Payout
    {
        return DB::transaction(function () use ($payout, $actor) {
            $locked = $this->lock($payout, [PayoutStatus::Requested], 'Only a request that finance has not reviewed yet can be cancelled.');

            return $this->close($locked, PayoutStatus::Cancelled, $actor, 'payout.cancelled', [], notify: false);
        });
    }

    public function approve(Payout $payout, User $actor, ?string $note): Payout
    {
        return DB::transaction(function () use ($payout, $actor, $note) {
            $locked = $this->lock($payout, [PayoutStatus::Requested], 'This payout has already been reviewed.');
            $this->assertAffiliateActive($locked);

            $locked->forceFill([
                'status' => PayoutStatus::Approved,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
            $this->audit->log('payout.approved', $locked, ['amount_minor' => $locked->amount_minor, 'note' => $note], $actor);
            $this->notifyAffiliate($locked);

            return $locked;
        });
    }

    public function reject(Payout $payout, User $actor, string $note): Payout
    {
        return DB::transaction(function () use ($payout, $actor, $note) {
            $locked = $this->lock($payout, self::openStatuses(), 'This payout is already closed.');
            $locked->forceFill(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note]);

            return $this->close($locked, PayoutStatus::Rejected, $actor, 'payout.rejected', ['note' => $note]);
        });
    }

    /**
     * Records a transfer finance has made. The reference is the bank or
     * wallet transaction id; it can settle only one payout per method.
     */
    public function markPaid(Payout $payout, User $actor, string $reference, ?string $note = null): Payout
    {
        $reference = trim($reference);

        try {
            return DB::transaction(function () use ($payout, $actor, $reference, $note) {
                $locked = $this->lock($payout, [PayoutStatus::Approved], fn (PayoutStatus $current) => $current === PayoutStatus::Paid
                    ? 'This payout is already marked as paid.'
                    : 'Only an approved payout can be marked as paid.');
                $this->assertAffiliateActive($locked);
                if (config('affiliate.payouts.require_separate_payer') && $locked->reviewed_by === $actor->id) {
                    throw ValidationException::withMessages(['payout' => 'Someone other than the approver must confirm the transfer.']);
                }
                if (Payout::query()->where('method_type', $locked->method_type->value)->where('payment_reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['reference' => 'That transfer reference is already recorded on another payout.']);
                }

                $this->ledger->post(
                    $locked->affiliate_profile_id, LedgerEntryType::PayoutPaid, LedgerBucket::Reserved, LedgerBucket::Paid,
                    $locked->amount_minor, $locked->currency, "payout:{$locked->id}:paid", ['payout_id' => $locked->id], $actor,
                );

                $commissionIds = $locked->items()->pluck('commission_id');
                $paidCommissions = Commission::query()
                    ->whereIn('id', $commissionIds)
                    ->where('status', CommissionStatus::Available->value)
                    ->lockForUpdate()
                    ->get();
                foreach ($paidCommissions as $commission) {
                    $commission->forceFill(['status' => CommissionStatus::Paid, 'paid_at' => now()])->save();
                }

                $locked->forceFill([
                    'status' => PayoutStatus::Paid,
                    'open_affiliate_profile_id' => null,
                    'paid_by' => $actor->id,
                    'paid_at' => now(),
                    'payment_reference' => $reference,
                    'closed_at' => now(),
                ])->save();

                $this->audit->log('payout.paid', $locked, [
                    'amount_minor' => $locked->amount_minor,
                    'reference' => $reference,
                    'commissions_paid' => $paidCommissions->count(),
                    'note' => $note,
                ], $actor);
                $this->notifyAffiliate($locked);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            // Two finance users entering the same reference at the same moment.
            throw ValidationException::withMessages(['reference' => 'That transfer reference is already recorded on another payout.']);
        }
    }

    /** The transfer was attempted and did not go through. */
    public function markFailed(Payout $payout, User $actor, string $reason): Payout
    {
        return DB::transaction(function () use ($payout, $actor, $reason) {
            $locked = $this->lock($payout, [PayoutStatus::Approved], 'Only an approved payout can be marked as failed.');
            $locked->failure_reason = $reason;

            return $this->close($locked, PayoutStatus::Failed, $actor, 'payout.failed', ['reason' => $reason]);
        });
    }

    /**
     * @return list<string>
     */
    public static function openStatuses(): array
    {
        return [PayoutStatus::Requested->value, PayoutStatus::Approved->value];
    }

    /**
     * Ends an open payout without paying it and returns the reserved funds.
     *
     * @param  array<string, mixed>  $properties
     */
    private function close(Payout $locked, PayoutStatus $status, User $actor, string $action, array $properties, bool $notify = true): Payout
    {
        $this->ledger->post(
            $locked->affiliate_profile_id, LedgerEntryType::PayoutRelease, LedgerBucket::Reserved, LedgerBucket::Available,
            $locked->amount_minor, $locked->currency, "payout:{$locked->id}:release", ['payout_id' => $locked->id], $actor,
        );

        $locked->forceFill([
            'status' => $status,
            'open_affiliate_profile_id' => null,
            'closed_at' => now(),
        ])->save();

        $this->audit->log($action, $locked, ['amount_minor' => $locked->amount_minor] + $properties, $actor);
        if ($notify) {
            $this->notifyAffiliate($locked);
        }

        return $locked;
    }

    /**
     * @param  list<PayoutStatus|string>  $allowed
     * @param  string|Closure(PayoutStatus): string  $message
     */
    private function lock(Payout $payout, array $allowed, string|Closure $message): Payout
    {
        $locked = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
        $allowed = array_map(fn ($s) => $s instanceof PayoutStatus ? $s : PayoutStatus::from($s), $allowed);

        if (! in_array($locked->status, $allowed, true)) {
            throw ValidationException::withMessages(['payout' => $message instanceof Closure ? $message($locked->status) : $message]);
        }

        return $locked;
    }

    private function assertAffiliateActive(Payout $payout): void
    {
        $affiliate = AffiliateProfile::query()->findOrFail($payout->affiliate_profile_id);
        if (! $affiliate->status->isEligibleToEarn()) {
            throw ValidationException::withMessages(['payout' => "The affiliate's membership is {$affiliate->status->value}. Reject the payout or reinstate the affiliate first."]);
        }
    }

    private function notifyAffiliate(Payout $payout): void
    {
        $user = AffiliateProfile::query()->with('user')->findOrFail($payout->affiliate_profile_id)->user;
        $user->notify((new PayoutStatusChanged($payout))->afterCommit());
    }
}
