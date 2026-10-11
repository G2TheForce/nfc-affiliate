<?php

namespace App\Http\Resources\Commissions;

use App\Models\CommissionAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommissionAdjustment
 */
class AdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'affiliate' => [
                'id' => $this->affiliate->public_id,
                'display_name' => $this->affiliate->display_name,
                'referral_code' => $this->affiliate->referral_code,
            ],
            'commission_id' => $this->commission?->public_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'created_by' => $this->creator?->name,
            'can_decide' => $request->user()?->id !== $this->created_by,
            'decided_by' => $this->decider?->name,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
