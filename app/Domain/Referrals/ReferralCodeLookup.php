<?php

namespace App\Domain\Referrals;

use App\Models\AffiliateProfile;
use Illuminate\Support\Str;

class ReferralCodeLookup
{
    /**
     * Finds the affiliate behind a referral code or slug. Returns null for
     * unknown identifiers and for affiliates who are not allowed to earn.
     */
    public function eligible(?string $identifier): ?AffiliateProfile
    {
        $identifier = trim((string) $identifier);

        if ($identifier === '' || strlen($identifier) > 60 || ! preg_match('/^[A-Za-z0-9-]+$/', $identifier)) {
            return null;
        }

        $profile = AffiliateProfile::query()
            ->where('referral_code', Str::upper($identifier))
            ->orWhere('referral_slug', Str::lower($identifier))
            ->first();

        return $profile?->status->isEligibleToEarn() ? $profile : null;
    }
}
