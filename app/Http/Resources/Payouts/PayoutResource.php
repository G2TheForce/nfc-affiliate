<?php

namespace App\Http\Resources\Payouts;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout as its affiliate sees it. Destination details are masked.
 *
 * @mixin Payout
 */
class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'method_type' => $this->method_type->value,
            'method_label' => $this->method_type->label(),
            'destination' => $this->destination_masked,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'reason' => match ($this->status) {
                PayoutStatus::Rejected => $this->review_note,
                PayoutStatus::Failed => $this->failure_reason,
                default => null,
            },
            'commission_count' => $this->whenCounted('items'),
            'can_cancel' => $this->status === PayoutStatus::Requested,
        ];
    }
}
