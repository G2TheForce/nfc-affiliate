<?php

namespace App\Http\Resources;

use App\Domain\Affiliates\AffiliateMembershipService;
use App\Models\AffiliateProfile;
use App\Models\AffiliateTermsVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The member's own view of their profile. Contains no internal notes, no
 * reviewer identities and no internal ids.
 *
 * @mixin AffiliateProfile
 */
class AffiliateProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $current = AffiliateTermsVersion::current();
        $reapplyAt = app(AffiliateMembershipService::class)->reapplyAvailableAt($this->resource);

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'display_name' => $this->display_name,
            'referral_code' => $this->referral_code,
            'referral_slug' => $this->referral_slug,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'address_line' => $this->address_line,
            'promotion_channels' => $this->promotion_channels,
            'website_urls' => $this->website_urls ?? [],
            'audience_description' => $this->audience_description,
            'applied_at' => $this->applied_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'can_reapply_at' => $reapplyAt?->toIso8601String(),
            'needs_terms_acceptance' => $current !== null && ! $this->hasAcceptedTerms($current),
            'current_terms_version' => $current?->version,
            'history' => $this->statusHistory->map(fn ($h) => [
                'status' => $h->to_status->value,
                'status_label' => $h->to_status->label(),
                'reason' => $h->public_reason,
                'at' => $h->created_at?->toIso8601String(),
            ])->values(),
            'terms_accepted' => $this->termsAcceptances->map(fn ($a) => [
                'version' => $a->termsVersion->version,
                'accepted_at' => $a->accepted_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
