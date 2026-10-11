<?php

namespace App\Domain\Referrals;

use App\Enums\AttributionSource;
use App\Models\AffiliateProfile;
use App\Models\Coupon;
use App\Models\ReferralClick;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Decides which affiliate, if any, an order belongs to (ADR 0007).
 *
 * Precedence with the default coupon_overrides_click = true:
 *   1. affiliate coupon used on the order
 *   2. referral code typed at checkout
 *   3. referral click in the attribution cookie (last-click by default)
 * With coupon_overrides_click = false the click comes first.
 *
 * A candidate is skipped when its affiliate cannot earn or when the buyer
 * is the affiliate (self-referral). If every candidate is skipped the order
 * is recorded as blocked with the first reason, so support can see why.
 * Exactly one affiliate is attributed per order.
 */
class AttributionResolver
{
    public function __construct(private readonly ReferralCodeLookup $lookup) {}

    public function resolve(
        ?ReferralClick $click,
        ?Coupon $coupon,
        ?string $typedReferralCode,
        ?User $customer,
        string $customerEmail,
        string $customerPhone,
    ): AttributionDecision {
        $fromCoupon = $coupon?->affiliate_profile_id
            ? fn () => [$coupon->affiliate, AttributionSource::Coupon, null, $coupon]
            : null;
        $fromCode = $typedReferralCode
            ? fn () => [$this->lookup->eligible($typedReferralCode), AttributionSource::ReferralCode, null, null]
            : null;
        $fromClick = $click
            ? fn () => [$click->affiliate, AttributionSource::Click, $click, null]
            : null;

        $candidates = config('affiliate.attribution.coupon_overrides_click')
            ? [$fromCoupon, $fromCode, $fromClick]
            : [$fromClick, $fromCoupon, $fromCode];

        $firstBlock = null;

        foreach (array_filter($candidates) as $candidate) {
            [$affiliate, $source, $candidateClick, $candidateCoupon] = $candidate();

            if (! $affiliate instanceof AffiliateProfile || ! $affiliate->status->isEligibleToEarn()) {
                $firstBlock ??= 'affiliate_not_eligible';

                continue;
            }

            if ($this->isSelfReferral($affiliate, $customer, $customerEmail, $customerPhone)) {
                $firstBlock ??= 'self_referral';

                continue;
            }

            return AttributionDecision::attributed($affiliate, $source, $candidateClick, $candidateCoupon);
        }

        return $firstBlock ? AttributionDecision::blocked($firstBlock) : AttributionDecision::none();
    }

    public function isSelfReferral(AffiliateProfile $affiliate, ?User $customer, string $customerEmail, string $customerPhone): bool
    {
        $owner = $affiliate->user;

        if ($customer && $customer->id === $owner->id) {
            return true;
        }

        if (Str::lower(trim($customerEmail)) === Str::lower($owner->email)) {
            return true;
        }

        return $owner->phone !== null && $owner->phone === $customerPhone;
    }
}
