<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Metrics;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\Permission;

class ProductPerformance extends Report
{
    public function __construct(private readonly Metrics $metrics) {}

    public function key(): string
    {
        return 'product-performance';
    }

    public function title(): string
    {
        return 'Product performance';
    }

    public function description(): string
    {
        return 'Units, revenue and net commission per product on attributed orders paid in the period.';
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
            new Column('name', 'Product'),
            new Column('units', 'Units', 'int'),
            new Column('orders', 'Orders', 'int'),
            new Column('revenue_minor', 'Revenue (paid)', 'money'),
            new Column('commission_minor', 'Net commission', 'money'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        return $this->metrics->products($period, $filters, null);
    }
}
