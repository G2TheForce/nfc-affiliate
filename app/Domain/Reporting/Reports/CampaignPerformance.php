<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Metrics;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\Permission;

class CampaignPerformance extends Report
{
    public function __construct(private readonly Metrics $metrics) {}

    public function key(): string
    {
        return 'campaign-performance';
    }

    public function title(): string
    {
        return 'Campaign (coupon) performance';
    }

    public function description(): string
    {
        return 'Attributed orders placed with each coupon, with revenue and net commission once paid.';
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
            new Column('code', 'Coupon'),
            new Column('affiliate', 'Affiliate'),
            new Column('orders', 'Orders placed', 'int'),
            new Column('revenue_minor', 'Revenue (paid)', 'money'),
            new Column('commission_minor', 'Net commission', 'money'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        return $this->metrics->campaigns($period, $filters, null);
    }
}
