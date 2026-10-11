<?php

namespace App\Domain\Store;

use App\Domain\Audit\AuditLogger;
use App\Domain\Referrals\ReferralEventRecorder;
use App\Enums\OrderStatus;
use App\Enums\ReferralEventType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReferralEventRecorder $events,
    ) {}

    /**
     * Cancels an unpaid order. The coupon use is voided so it no longer
     * counts towards limits; paid orders go through refunds instead (Phase 4).
     */
    public function cancel(Order $order, User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::PendingPayment) {
                throw ValidationException::withMessages(['status' => 'Only orders awaiting payment can be cancelled.']);
            }

            $locked->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            $locked->couponRedemption()->whereNull('voided_at')->update(['voided_at' => now()]);

            if ($attribution = $locked->attribution()->first()) {
                $this->events->record(ReferralEventType::OrderCancelled, $attribution->affiliate_profile_id, $locked);
            }

            $this->audit->log('order.cancelled', $locked, ['reason' => $reason], $actor);

            return $locked;
        });
    }
}
