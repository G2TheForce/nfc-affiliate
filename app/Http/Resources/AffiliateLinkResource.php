<?php

namespace App\Http\Resources;

use App\Models\AffiliateLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AffiliateLink
 */
class AffiliateLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'url' => url('/ref/'.$this->affiliate->referral_code.'/'.$this->slug),
            'product' => $this->product ? ['id' => $this->product->public_id, 'name' => $this->product->name, 'slug' => $this->product->slug] : null,
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
