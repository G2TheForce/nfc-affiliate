<?php

namespace App\Http\Resources\Store;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer's own view of an order. Attribution is not shown.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'discount_minor' => $this->discount_minor,
            'shipping_minor' => $this->shipping_minor,
            'total_minor' => $this->total_minor,
            'coupon_code' => $this->coupon?->code,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'shipping_city' => $this->shipping_city,
            'shipping_address' => $this->shipping_address,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'items' => $this->items->map(fn ($item) => [
                'sku' => $item->sku,
                'name' => $item->name,
                'unit_price_minor' => $item->unit_price_minor,
                'quantity' => $item->quantity,
                'discount_minor' => $item->discount_minor,
                'line_total_minor' => $item->line_total_minor,
            ])->values(),
        ];
    }
}
