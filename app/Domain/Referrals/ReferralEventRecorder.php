<?php

namespace App\Domain\Referrals;

use App\Enums\ReferralEventType;
use App\Models\Order;
use App\Models\ReferralEvent;

/**
 * Records order lifecycle events for an affiliate. The unique dedupe key
 * makes each event idempotent: replaying the same event is a no-op.
 */
class ReferralEventRecorder
{
    public function record(ReferralEventType $type, int $affiliateProfileId, Order $order, ?int $amountMinor = null): bool
    {
        return ReferralEvent::query()->insertOrIgnore([
            'affiliate_profile_id' => $affiliateProfileId,
            'type' => $type->value,
            'order_id' => $order->id,
            'amount_minor' => $amountMinor,
            'dedupe_key' => $type->value.':'.$order->id.':'.$affiliateProfileId,
            'occurred_at' => now(),
        ]) === 1;
    }
}
