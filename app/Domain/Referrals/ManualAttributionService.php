<?php

namespace App\Domain\Referrals;

use App\Domain\Audit\AuditLogger;
use App\Domain\Commissions\CommissionService;
use App\Enums\AttributionSource;
use App\Enums\AttributionStatus;
use App\Enums\OrderStatus;
use App\Enums\ReferralEventType;
use App\Models\AffiliateProfile;
use App\Models\Order;
use App\Models\OrderAttribution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff assign an order to an affiliate, e.g. when a customer says they were
 * referred but the cookie was lost. A reason is required and the previous
 * attribution is kept in the audit log. On a paid order the commission is
 * created at once; once commissions exist the attribution is fixed, and
 * corrections go through void and adjustments instead.
 */
class ManualAttributionService
{
    public function __construct(
        private readonly AttributionResolver $resolver,
        private readonly ReferralEventRecorder $events,
        private readonly AuditLogger $audit,
        private readonly CommissionService $commissions,
    ) {}

    public function assign(Order $order, AffiliateProfile $affiliate, User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $affiliate, $actor, $reason) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [OrderStatus::Cancelled, OrderStatus::Refunded, OrderStatus::PartiallyRefunded], true)) {
                throw ValidationException::withMessages(['order' => 'Cancelled or refunded orders cannot be attributed.']);
            }
            if ($locked->commissions()->exists()) {
                throw ValidationException::withMessages(['order' => 'Commission has already been created for this order. Void it and use an adjustment instead.']);
            }
            if (! $affiliate->status->isEligibleToEarn()) {
                throw ValidationException::withMessages(['affiliate_id' => 'This affiliate is not approved.']);
            }
            if ($this->resolver->isSelfReferral($affiliate, $locked->customer, $locked->customer_email, $locked->customer_phone)) {
                throw ValidationException::withMessages(['affiliate_id' => 'The customer is this affiliate; self-referrals are not attributed.']);
            }

            $previous = OrderAttribution::query()->with('affiliate')->where('order_id', $locked->id)->first();

            if ($previous?->affiliate_profile_id === $affiliate->id) {
                throw ValidationException::withMessages(['affiliate_id' => 'The order is already attributed to this affiliate.']);
            }

            $previousAffiliate = $previous?->affiliate?->public_id;
            $previousSource = $previous?->source?->value;

            $attribution = $previous ?? new OrderAttribution;
            $attribution->forceFill([
                'order_id' => $locked->id,
                'affiliate_profile_id' => $affiliate->id,
                'source' => AttributionSource::Manual,
                'referral_click_id' => null,
                'coupon_id' => null,
                'policy' => 'manual',
                'window_days' => (int) config('affiliate.attribution.window_days'),
                'clicked_at' => null,
                'decided_by' => $actor->id,
                'reason' => $reason,
            ])->save();

            $locked->forceFill(['attribution_status' => AttributionStatus::Attributed, 'attribution_block_reason' => null])->save();

            $this->events->record(ReferralEventType::OrderPlaced, $affiliate->id, $locked, $locked->subtotal_minor - $locked->discount_minor);

            $this->audit->log('order.attribution.manual', $locked, [
                'reason' => $reason,
                'affiliate' => $affiliate->public_id,
                'previous_affiliate' => $previousAffiliate,
                'previous_source' => $previousSource,
            ], $actor);

            if ($locked->status->isPaid()) {
                $this->commissions->createForOrder($locked->load('coupon'), $actor);
            }

            return $locked;
        });
    }
}
