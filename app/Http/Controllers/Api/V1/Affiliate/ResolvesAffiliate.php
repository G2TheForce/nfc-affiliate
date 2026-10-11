<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Models\AffiliateProfile;
use Illuminate\Http\Request;

trait ResolvesAffiliate
{
    /**
     * The signed-in user's own affiliate profile. Members who were never
     * approved have no referral code and so nothing to track.
     */
    protected function trackedProfile(Request $request): AffiliateProfile
    {
        $profile = AffiliateProfile::query()->where('user_id', $request->user()->id)->first();

        abort_if(! $profile || ! $profile->referral_code, 403, 'Referral tools are available once your application is approved.');

        return $profile;
    }

    protected function earningProfile(Request $request): AffiliateProfile
    {
        $profile = $this->trackedProfile($request);

        abort_unless($profile->status->isEligibleToEarn(), 403, 'Your affiliate membership is not active.');

        return $profile;
    }
}
