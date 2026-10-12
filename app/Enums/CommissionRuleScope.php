<?php

namespace App\Enums;

enum CommissionRuleScope: string
{
    case Global = 'global';
    case Plan = 'plan';
    case Category = 'category';
    case Product = 'product';
    case Affiliate = 'affiliate';
    case Campaign = 'campaign';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Everyone',
            self::Plan => 'Commission plan',
            self::Category => 'Product category',
            self::Product => 'Product',
            self::Affiliate => 'One affiliate',
            self::Campaign => 'Campaign',
        };
    }

    /** The rules-table column that names this scope's target, if any. */
    public function targetColumn(): ?string
    {
        return match ($this) {
            self::Global => null,
            self::Plan => 'commission_plan_id',
            self::Category => 'product_category_id',
            self::Product => 'product_id',
            self::Affiliate => 'affiliate_profile_id',
            self::Campaign => 'campaign_id',
        };
    }
}
