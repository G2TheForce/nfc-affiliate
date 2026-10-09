<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case AffiliateManager = 'affiliate_manager';
    case FinanceManager = 'finance_manager';
    case Support = 'support';
    case Affiliate = 'affiliate';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Administrator',
            self::AffiliateManager => 'Affiliate Manager',
            self::FinanceManager => 'Finance Manager',
            self::Support => 'Customer Support',
            self::Affiliate => 'Affiliate Member',
            self::Customer => 'Customer',
        };
    }

    public function isStaff(): bool
    {
        return in_array($this, [self::SuperAdmin, self::AffiliateManager, self::FinanceManager, self::Support], true);
    }
}
