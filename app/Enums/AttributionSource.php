<?php

namespace App\Enums;

enum AttributionSource: string
{
    case Click = 'click';
    case Coupon = 'coupon';
    case ReferralCode = 'referral_code';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Click => 'Referral link',
            self::Coupon => 'Affiliate coupon',
            self::ReferralCode => 'Referral code at checkout',
            self::Manual => 'Manual (staff)',
        };
    }
}
