<?php

namespace App\Domain\Store;

use App\Domain\Audit\AuditLogger;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Notifications\Store\OrderShipped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Moves an order through preparation, shipping and delivery. No courier API
 * is connected: staff enter the courier and tracking number themselves.
 * Steps only move forward; a cancelled or fully refunded order is not shipped.
 */
class FulfilmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array{carrier?: ?string, tracking_number?: ?string}  $details */
    public function advance(Order $order, User $actor, FulfilmentStatus $to, array $details = []): Order
    {
        return DB::transaction(function () use ($order, $actor, $to, $details) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
                throw ValidationException::withMessages(['order' => 'A cancelled or refunded order cannot be shipped.']);
            }
            if ($to->rank() <= $locked->fulfilment_status->rank()) {
                throw ValidationException::withMessages(['status' => "This order is already {$locked->fulfilment_status->label()}."]);
            }
            if ($to === FulfilmentStatus::Shipped && blank($details['carrier'] ?? null)) {
                throw ValidationException::withMessages(['carrier' => 'Enter the courier the order was handed to.']);
            }

            $now = now();
            $changes = ['fulfilment_status' => $to];
            if ($to->rank() >= FulfilmentStatus::Processing->rank()) {
                $changes['processing_at'] = $locked->processing_at ?? $now;
            }
            if ($to->rank() >= FulfilmentStatus::Shipped->rank()) {
                $changes['shipped_at'] = $locked->shipped_at ?? $now;
                if ($to === FulfilmentStatus::Shipped) {
                    $changes['carrier'] = trim((string) $details['carrier']);
                    $changes['tracking_number'] = filled($details['tracking_number'] ?? null) ? trim((string) $details['tracking_number']) : null;
                }
            }
            if ($to === FulfilmentStatus::Delivered) {
                $changes['delivered_at'] = $now;
            }
            $locked->forceFill($changes)->save();

            $this->audit->log('order.fulfilment_'.$to->value, $locked, array_filter([
                'carrier' => $changes['carrier'] ?? null,
                'tracking_number' => $changes['tracking_number'] ?? null,
            ]), $actor);

            if ($to === FulfilmentStatus::Shipped) {
                Notification::route('mail', $locked->customer_email)->notify((new OrderShipped($locked))->afterCommit());
            }

            return $locked;
        });
    }
}
