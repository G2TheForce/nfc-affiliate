<?php

namespace App\Http\Resources\Commissions;

use App\Models\Commission;
use App\Support\Privacy\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A commission line. The affiliate view masks the order number and leaves
 * out staff-only fields (hold reason, rule ids, the full snapshot).
 *
 * @mixin Commission
 */
class CommissionResource extends JsonResource
{
    /** True in StaffCommissionResource. */
    protected bool $staff = false;

    public function toArray(Request $request): array
    {
        $line = $this->snapshot['line'] ?? [];
        $orderNumber = (string) ($this->snapshot['order']['number'] ?? '');

        return [
            'id' => $this->public_id,
            'order_number' => $this->staff ? $orderNumber : Mask::orderNumber($orderNumber),
            'order_id' => $this->when($this->staff && $this->relationLoaded('order'), fn () => $this->order?->public_id),
            'affiliate' => $this->when($this->staff && $this->relationLoaded('affiliate'), fn () => $this->affiliate ? [
                'id' => $this->affiliate->public_id,
                'display_name' => $this->affiliate->display_name,
                'referral_code' => $this->affiliate->referral_code,
            ] : null),
            'product_name' => $line['name'] ?? null,
            'sku' => $line['sku'] ?? null,
            'quantity' => $this->quantity,
            'kind' => $this->kind->value,
            'rate_bp' => $this->rate_bp,
            'fixed_minor' => $this->fixed_minor,
            'base_minor' => $this->base_minor,
            'amount_minor' => $this->amount_minor,
            'reversed_minor' => $this->reversed_minor,
            'net_minor' => $this->netMinor(),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'partially_reversed' => $this->reversed_minor > 0 && $this->netMinor() > 0,
            'on_hold' => $this->isHeld(),
            'hold_reason' => $this->when($this->staff, $this->hold_reason),
            'rule' => $this->when($this->staff, fn () => [
                'id' => $this->snapshot['rule']['rule_id'] ?? null,
                'name' => $this->snapshot['rule']['name'] ?? null,
                'scope' => $this->rule_scope,
                'version' => $this->rule_version,
            ]),
            'eligible_at' => $this->eligible_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'available_at' => $this->available_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
