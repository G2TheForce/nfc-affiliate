<?php

namespace App\Domain\Commissions;

use App\Domain\Audit\AuditLogger;
use App\Enums\AttributionStatus;
use App\Enums\CommissionRuleKind;
use App\Enums\CommissionStatus;
use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use App\Models\AffiliateProfile;
use App\Models\Commission;
use App\Models\Order;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\User;
use App\Notifications\Affiliate\CommissionReversed;
use App\Notifications\Affiliate\ReferredOrderPaid;
use App\Support\Privacy\Mask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Creates commissions when an order is paid and reverses them when it is
 * refunded. Callers hold the order row lock inside a transaction; the unique
 * (order_item_id, affiliate_profile_id) key and the ledger idempotency keys
 * make both operations safe to replay.
 */
class CommissionService
{
    public function __construct(
        private readonly RuleResolver $rules,
        private readonly CommissionLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, Commission> the commissions created by this call
     */
    public function createForOrder(Order $order, ?User $actor = null): Collection
    {
        $this->assertInTransaction();
        $created = collect();

        if (! $order->status->isPaid() || $order->attribution_status !== AttributionStatus::Attributed) {
            return $created;
        }

        $attribution = $order->attribution()->with(['affiliate', 'campaign.products'])->first();
        if (! $attribution) {
            return $created;
        }
        $affiliate = $attribution->affiliate;

        if ($order->coupon && ! $order->coupon->earns_commission) {
            $this->audit->log('commission.skipped', $order, ['reason' => 'coupon_does_not_earn', 'coupon' => $order->coupon->code], $actor);

            return $created;
        }

        $placedAt = $order->placed_at;
        // The campaign was checked when the order was placed; switching it off since stops its rate.
        $campaign = $attribution->campaign?->is_active ? $attribution->campaign : null;
        $campaignProducts = $campaign && $campaign->products->isNotEmpty() ? $campaign->products->pluck('id')->all() : null;
        $candidates = $this->rules->candidates($affiliate, $placedAt, $campaign);
        $holdingDays = (int) config('affiliate.commissions.holding_period_days');
        $eligibleAt = $order->paid_at->copy()->addDays($holdingDays);
        $hold = ! $affiliate->status->isEligibleToEarn();
        $skipped = [];

        foreach ($order->items()->orderBy('id')->get() as $item) {
            $rule = $this->rules->pick($candidates, $item->product_id, $item->category_id, $campaignProducts);

            $reason = match (true) {
                $rule === null => 'no_rule',
                $rule->kind === CommissionRuleKind::Exclude => 'excluded',
                $rule->kind === CommissionRuleKind::Fixed && $rule->currency !== $order->currency => 'currency_mismatch',
                default => null,
            };
            if ($reason) {
                $skipped[] = ['sku' => $item->sku, 'reason' => $reason, 'rule' => $rule?->public_id];

                continue;
            }

            $base = $item->line_total_minor;
            $amount = CommissionMath::amount($rule->kind, $base, $item->quantity, $rule->rate_bp, $rule->fixed_minor);
            if ($amount <= 0) {
                $skipped[] = ['sku' => $item->sku, 'reason' => 'zero_amount', 'rule' => $rule->public_id];

                continue;
            }

            if (Commission::query()->where('order_item_id', $item->id)->where('affiliate_profile_id', $affiliate->id)->exists()) {
                continue;
            }

            $commission = new Commission;
            $commission->forceFill([
                'affiliate_profile_id' => $affiliate->id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'commission_rule_id' => $rule->id,
                'rule_version' => $rule->version,
                'rule_scope' => $rule->scope->value,
                'kind' => $rule->kind,
                'base_minor' => $base,
                'rate_bp' => $rule->kind === CommissionRuleKind::Percent ? $rule->rate_bp : null,
                'fixed_minor' => $rule->kind === CommissionRuleKind::Fixed ? $rule->fixed_minor : null,
                'quantity' => $item->quantity,
                'amount_minor' => $amount,
                'currency' => $order->currency,
                'status' => CommissionStatus::Pending,
                'eligible_at' => $eligibleAt,
                'held_at' => $hold ? now() : null,
                'hold_reason' => $hold ? 'Affiliate membership was '.$affiliate->status->value.' when the order was paid.' : null,
                'snapshot' => [
                    'rule' => $rule->snapshot(),
                    'line' => [
                        'sku' => $item->sku,
                        'name' => $item->name,
                        'unit_price_minor' => $item->unit_price_minor,
                        'quantity' => $item->quantity,
                        'line_subtotal_minor' => $item->line_subtotal_minor,
                        'discount_minor' => $item->discount_minor,
                        'line_total_minor' => $item->line_total_minor,
                    ],
                    'order' => [
                        'number' => $order->order_number,
                        'currency' => $order->currency,
                        'placed_at' => $placedAt->toIso8601String(),
                        'paid_at' => $order->paid_at->toIso8601String(),
                        'coupon' => $order->coupon?->code,
                    ],
                    'attribution' => [
                        'source' => $attribution->source->value,
                        'policy' => $attribution->policy,
                        'campaign' => $campaign?->public_id,
                    ],
                    'policy' => [
                        'base' => 'line total after discounts; shipping and payment fees excluded',
                        'rounding' => 'half_up_per_line',
                        'holding_period_days' => $holdingDays,
                    ],
                    'affiliate_status' => $affiliate->status->value,
                ],
            ])->save();

            $this->ledger->post(
                $affiliate->id, LedgerEntryType::Accrual, null, LedgerBucket::Pending, $amount, $order->currency,
                "commission:{$commission->id}:accrue", ['commission_id' => $commission->id], $actor,
            );

            $created->push($commission);
        }

        $this->audit->log('commission.created', $order, [
            'affiliate' => $affiliate->public_id,
            'commissions' => $created->map(fn (Commission $c) => ['id' => $c->public_id, 'amount_minor' => $c->amount_minor])->all(),
            'skipped' => $skipped,
            'held' => $hold,
        ], $actor);

        // A replay that creates nothing new on an order already handled stays quiet.
        if ($created->isNotEmpty() || $skipped !== []) {
            $affiliate->user?->notify((new ReferredOrderPaid(
                Mask::orderNumber($order->order_number), (int) $created->sum('amount_minor'), $order->currency, $eligibleAt, $hold,
            ))->afterCommit());
        }

        return $created;
    }

    /**
     * Reverses only the commission on the refunded lines. Each commission is
     * recomputed on what remains of its line after all refunds so far; the
     * difference moves to the reversed bucket. An already-paid commission is
     * clawed back from the available balance, which may go negative
     * (a recoverable balance) rather than deleting anything.
     *
     * @return int total commission reversed by this refund
     */
    public function reverseForRefund(Refund $refund, ?User $actor = null): int
    {
        $this->assertInTransaction();
        $total = 0;
        $byAffiliate = [];

        foreach ($refund->items()->get() as $refundItem) {
            $refundedForLine = (int) RefundItem::query()->where('order_item_id', $refundItem->order_item_id)->sum('amount_minor');

            $commissions = Commission::query()
                ->where('order_item_id', $refundItem->order_item_id)
                ->where('status', '!=', CommissionStatus::Reversed->value)
                ->lockForUpdate()
                ->get();

            foreach ($commissions as $commission) {
                $remaining = CommissionMath::remainingAfterRefund(
                    $commission->kind, $commission->amount_minor, $commission->base_minor, $refundedForLine, $commission->rate_bp,
                );
                $delta = $commission->netMinor() - $remaining;
                if ($delta <= 0) {
                    continue;
                }

                $from = $commission->status === CommissionStatus::Paid ? LedgerBucket::Available : $commission->status->bucket();
                $posted = $this->ledger->post(
                    $commission->affiliate_profile_id, LedgerEntryType::RefundReversal, $from, LedgerBucket::Reversed,
                    $delta, $commission->currency, "refund:{$refund->id}:commission:{$commission->id}",
                    ['commission_id' => $commission->id, 'refund_id' => $refund->id], $actor,
                );
                if (! $posted) {
                    continue;
                }

                $commission->reversed_minor += $delta;
                if ($remaining === 0 && $commission->status !== CommissionStatus::Paid) {
                    $commission->status = CommissionStatus::Reversed;
                    $commission->reversed_at = now();
                }
                $commission->save();
                $total += $delta;
                $byAffiliate[$commission->affiliate_profile_id][$commission->currency] = ($byAffiliate[$commission->affiliate_profile_id][$commission->currency] ?? 0) + $delta;
            }
        }

        if ($byAffiliate !== []) {
            $reference = Mask::orderNumber($refund->order()->value('order_number'));
            $affiliates = AffiliateProfile::query()->with('user')->whereKey(array_keys($byAffiliate))->get();
            foreach ($affiliates as $affiliate) {
                foreach ($byAffiliate[$affiliate->id] as $currency => $amount) {
                    $affiliate->user?->notify((new CommissionReversed($reference, $amount, $currency, CommissionReversed::CAUSE_REFUND))->afterCommit());
                }
            }
        }

        return $total;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Commission changes must run inside the order transaction.');
        }
    }
}
