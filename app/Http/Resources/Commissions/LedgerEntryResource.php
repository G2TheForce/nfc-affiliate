<?php

namespace App\Http\Resources\Commissions;

use App\Models\CommissionLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommissionLedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'from_bucket' => $this->from_bucket?->value,
            'to_bucket' => $this->to_bucket?->value,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'commission_id' => $this->commission?->public_id,
            'adjustment_id' => $this->adjustment?->public_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
