<?php

namespace App\Domain\Reporting;

/**
 * Optional narrowing for staff reports. Internal ids, resolved from public
 * ids by the controller. Clicks are narrowed by affiliate only: a click is
 * not tied to a product or coupon until an order follows.
 */
final class Filters
{
    public function __construct(
        public readonly ?int $affiliateId = null,
        public readonly ?int $productId = null,
        public readonly ?int $couponId = null,
    ) {}

    public static function forAffiliate(int $affiliateId): self
    {
        return new self(affiliateId: $affiliateId);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return array_filter(['affiliate_id' => $this->affiliateId, 'product_id' => $this->productId, 'coupon_id' => $this->couponId]);
    }
}
