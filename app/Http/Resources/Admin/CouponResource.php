<?php

namespace App\Http\Resources\Admin;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Coupon
 */
class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'affiliate' => $this->affiliate ? [
                'id' => $this->affiliate->public_id,
                'display_name' => $this->affiliate->display_name,
                'referral_code' => $this->affiliate->referral_code,
                'status' => $this->affiliate->status->value,
            ] : null,
            'discount_type' => $this->discount_type->value,
            'discount_value' => $this->discount_value,
            'min_order_minor' => $this->min_order_minor,
            'max_discount_minor' => $this->max_discount_minor,
            'usage_limit' => $this->usage_limit,
            'per_customer_limit' => $this->per_customer_limit,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'earns_commission' => $this->earns_commission,
            'campaign' => $this->campaign ? ['id' => $this->campaign->public_id, 'name' => $this->campaign->name] : null,
            'products' => $this->products->map(fn ($p) => ['id' => $p->public_id, 'name' => $p->name])->values(),
            'times_used' => (int) ($this->active_redemptions_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
