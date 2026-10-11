<?php

namespace App\Http\Resources\Admin;

use App\Models\AffiliateProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AffiliateProfile
 */
class AffiliateListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'display_name' => $this->display_name,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'referral_code' => $this->referral_code,
            'country_code' => $this->country_code,
            'promotion_channels' => $this->promotion_channels,
            'applied_at' => $this->applied_at?->toIso8601String(),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
        ];
    }
}
