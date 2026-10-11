<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Metrics;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\Permission;

class AffiliatePerformance extends Report
{
    public function __construct(private readonly Metrics $metrics) {}

    public function key(): string
    {
        return 'affiliate-performance';
    }

    public function title(): string
    {
        return 'Clicks and conversions by affiliate';
    }

    public function description(): string
    {
        return 'Clicks, attributed orders, conversion, revenue and commission per affiliate.';
    }

    public function permission(): Permission
    {
        return Permission::ReportsView;
    }

    public function filters(): array
    {
        return ['affiliate_id', 'product_id', 'coupon_id'];
    }

    public function columns(): array
    {
        return [
            new Column('display_name', 'Affiliate'),
            new Column('referral_code', 'Referral code'),
            new Column('status', 'Status'),
            new Column('clicks', 'Clicks', 'int'),
            new Column('unique_clicks', 'Unique clicks', 'int'),
            new Column('orders', 'Attributed orders', 'int'),
            new Column('conversion_rate', 'Conversion %', 'percent'),
            new Column('revenue_minor', 'Revenue (paid)', 'money'),
            new Column('commission_earned_minor', 'Commission earned', 'money'),
            new Column('commission_reversed_minor', 'Commission reversed', 'money'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        return $this->metrics->affiliates($period, $filters, null);
    }
}
