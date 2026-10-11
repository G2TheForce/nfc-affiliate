<?php

namespace App\Http\Resources\Admin;

use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentAttempt
 */
class PaymentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order' => $this->whenLoaded('order', fn () => ['id' => $this->order->public_id, 'order_number' => $this->order->order_number]),
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'provider_transaction_id' => $this->provider_transaction_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'provider_status' => $this->provider_status,
            'review_reason' => $this->review_reason,
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'check_count' => $this->check_count,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'next_check_at' => $this->next_check_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
