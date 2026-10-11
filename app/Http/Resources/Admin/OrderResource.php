<?php

namespace App\Http\Resources\Admin;

use App\Enums\Permission;
use App\Http\Resources\Commissions\StaffCommissionResource;
use App\Models\Order;
use App\Support\Privacy\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Staff view of an order. Customer contact details are masked; attribution
 * is included only for staff with attribution.view.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    private bool $detail = false;

    /**
     * Full view for the order page: items, click details and reasons.
     * (Not a constructor flag: collections pass the item key as the second argument.)
     */
    public static function detail(Order $order): self
    {
        $resource = new self($order);
        $resource->detail = true;

        return $resource;
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $attribution = $this->attribution;

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
            'refunded_minor' => $this->refunded_minor,
            'coupon_code' => $this->coupon?->code,
            'customer_name' => $this->customer_name,
            'customer_email' => Mask::email($this->customer_email),
            'customer_phone' => Mask::phone($this->customer_phone),
            'shipping_city' => $this->shipping_city,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->when($this->detail, $this->cancel_reason),
            'items' => $this->when($this->detail, fn () => $this->items->map(fn ($item) => [
                'id' => $item->public_id,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit_price_minor' => $item->unit_price_minor,
                'quantity' => $item->quantity,
                'discount_minor' => $item->discount_minor,
                'line_total_minor' => $item->line_total_minor,
                'refunded_minor' => (int) ($item->refunded_minor ?? 0),
            ])->values()),
            'payment' => $this->when($this->detail, fn () => $this->payment ? [
                'id' => $this->payment->public_id,
                'provider' => $this->payment->provider,
                'method' => $this->payment->method->value,
                'method_label' => $this->payment->method->label(),
                'reference' => $this->payment->reference,
                'amount_minor' => $this->payment->amount_minor,
                'note' => $this->payment->note,
                'confirmed_by' => $this->payment->confirmer?->name,
                'confirmed_at' => $this->payment->confirmed_at?->toIso8601String(),
            ] : null),
            'refunds' => $this->when($this->detail, fn () => $this->refunds->sortBy('id')->map(fn ($refund) => [
                'id' => $refund->public_id,
                'reference' => $refund->reference,
                'amount_minor' => $refund->amount_minor,
                'shipping_minor' => $refund->shipping_minor,
                'reason' => $refund->reason,
                'items' => $refund->items->map(fn ($ri) => ['sku' => $ri->orderItem?->sku, 'amount_minor' => $ri->amount_minor])->values(),
                'recorded_by' => $refund->recorder?->name,
                'completed_at' => $refund->completed_at?->toIso8601String(),
            ])->values()),
            'commissions' => $this->when(
                $this->detail && $user?->can(Permission::CommissionsView->value),
                fn () => StaffCommissionResource::collection($this->commissions->sortBy('id')->values())->resolve($request),
            ),
            'attribution' => $this->when($user?->can(Permission::AttributionView->value), fn () => [
                'status' => $this->attribution_status->value,
                'block_reason' => $this->attribution_block_reason,
                'affiliate' => $attribution ? [
                    'id' => $attribution->affiliate->public_id,
                    'display_name' => $attribution->affiliate->display_name,
                    'referral_code' => $attribution->affiliate->referral_code,
                ] : null,
                'source' => $attribution?->source->value,
                'source_label' => $attribution?->source->label(),
                'policy' => $attribution?->policy,
                'clicked_at' => $attribution?->clicked_at?->toIso8601String(),
                'click' => $this->detail && $attribution?->click ? [
                    'landing_path' => $attribution->click->landing_path,
                    'referrer_host' => $attribution->click->referrer_host,
                    'utm_source' => $attribution->click->utm_source,
                    'utm_medium' => $attribution->click->utm_medium,
                    'utm_campaign' => $attribution->click->utm_campaign,
                    'sub_id' => $attribution->click->sub_id,
                    'link' => $attribution->click->link?->name,
                ] : null,
                'decided_by' => $attribution?->decider?->name,
                'reason' => $this->detail ? $attribution?->reason : null,
            ]),
        ];
    }
}
