<?php

namespace App\Domain\Referrals;

use App\Enums\AttributionSource;
use App\Enums\AttributionStatus;
use App\Models\AffiliateProfile;
use App\Models\Coupon;
use App\Models\ReferralClick;

final class AttributionDecision
{
    private function __construct(
        public readonly AttributionStatus $status,
        public readonly ?AffiliateProfile $affiliate = null,
        public readonly ?AttributionSource $source = null,
        public readonly ?ReferralClick $click = null,
        public readonly ?Coupon $coupon = null,
        public readonly ?string $blockReason = null,
    ) {}

    public static function attributed(AffiliateProfile $affiliate, AttributionSource $source, ?ReferralClick $click = null, ?Coupon $coupon = null): self
    {
        return new self(AttributionStatus::Attributed, $affiliate, $source, $click, $coupon);
    }

    public static function none(): self
    {
        return new self(AttributionStatus::None);
    }

    public static function blocked(string $reason): self
    {
        return new self(AttributionStatus::Blocked, blockReason: $reason);
    }
}
