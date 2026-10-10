<?php

namespace App\Domain\Affiliates;

use App\Models\AffiliateProfile;
use Illuminate\Support\Str;

/**
 * Public referral identifiers. Codes are random (not derived from database
 * ids) and use an alphabet without look-alike characters (0/O, 1/I/L).
 */
class ReferralIdentifierGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function code(): string
    {
        $length = (int) config('affiliate.applications.referral_code_length', 8);

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (AffiliateProfile::where('referral_code', $code)->exists());

        return $code;
    }

    public function slug(string $displayName, string $code): string
    {
        $base = Str::limit(Str::slug($displayName), 40, '') ?: 'affiliate';
        $slug = $base;
        $suffix = 2;

        while (AffiliateProfile::where('referral_slug', $slug)->exists()) {
            $slug = $suffix > 5 ? $base.'-'.Str::lower($code) : $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
