<?php

namespace App\Enums;

enum FraudSignal: string
{
    case HighConversion = 'high_conversion';
    case ClicksWithoutOrders = 'clicks_without_orders';
    case RefundRate = 'refund_rate';
    case RapidEarnings = 'rapid_earnings';
    case LargePayout = 'large_payout';
    case PayoutAfterDetailChange = 'payout_after_detail_change';

    public function label(): string
    {
        return match ($this) {
            self::HighConversion => 'Unusually high conversion',
            self::ClicksWithoutOrders => 'Many clicks, no orders',
            self::RefundRate => 'High refund rate',
            self::RapidEarnings => 'Sudden jump in earnings',
            self::LargePayout => 'Large payout request',
            self::PayoutAfterDetailChange => 'Payout soon after changing payout details',
        };
    }

    /** What a reviewer should look at. */
    public function guidance(): string
    {
        return match ($this) {
            self::HighConversion => 'Self-purchases or orders from friends. Compare customer names and addresses on the referred orders.',
            self::ClicksWithoutOrders => 'Bought or automated traffic. Check the referrer sites on the affiliate\'s clicks.',
            self::RefundRate => 'Orders placed to earn commission and then refunded.',
            self::RapidEarnings => 'A real campaign, or a burst of self-purchases or stolen-card orders.',
            self::LargePayout => 'Check the commissions in the payout before approving.',
            self::PayoutAfterDetailChange => 'A taken-over account sending money to a new destination. Confirm the change with the affiliate by phone.',
        };
    }
}
