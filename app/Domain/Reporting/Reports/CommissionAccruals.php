<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Metrics;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\Permission;

class CommissionAccruals extends Report
{
    public function __construct(private readonly Metrics $metrics) {}

    public function key(): string
    {
        return 'commission-accruals';
    }

    public function title(): string
    {
        return 'Commission accruals';
    }

    public function description(): string
    {
        return 'Every commission on orders paid in the period, with what refunds have reversed and its current status.';
    }

    public function permission(): Permission
    {
        return Permission::CommissionsView;
    }

    public function filters(): array
    {
        return ['affiliate_id', 'product_id', 'coupon_id'];
    }

    public function columns(): array
    {
        return [
            new Column('paid_at', 'Order paid', 'datetime'),
            new Column('commission_id', 'Commission ID'),
            new Column('affiliate', 'Affiliate'),
            new Column('order_number', 'Order'),
            new Column('product', 'Product'),
            new Column('base_minor', 'Commission base', 'money'),
            new Column('rate', 'Rate'),
            new Column('amount_minor', 'Commission', 'money'),
            new Column('reversed_minor', 'Reversed', 'money'),
            new Column('net_minor', 'Net', 'money'),
            new Column('status', 'Status'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        $query = $this->metrics->commissions($period, $filters)
            ->join('affiliate_profiles', 'affiliate_profiles.id', '=', 'commissions.affiliate_profile_id')
            ->join('order_items as line', 'line.id', '=', 'commissions.order_item_id')
            ->select('commissions.id', 'commissions.public_id', 'orders.paid_at', 'orders.order_number', 'affiliate_profiles.display_name', 'line.name as product',
                'commissions.base_minor', 'commissions.kind', 'commissions.rate_bp', 'commissions.fixed_minor', 'commissions.currency',
                'commissions.amount_minor', 'commissions.reversed_minor', 'commissions.status');

        foreach ($query->lazyById(500, 'commissions.id', 'id') as $c) {
            yield [
                'paid_at' => $c->paid_at,
                'commission_id' => $c->public_id,
                'affiliate' => $c->display_name,
                'order_number' => $c->order_number,
                'product' => $c->product,
                'base_minor' => (int) $c->base_minor,
                'rate' => $c->kind === 'percent' ? rtrim(rtrim(number_format($c->rate_bp / 100, 2), '0'), '.').'%' : $c->currency.' '.number_format($c->fixed_minor / 100, 2).' per unit',
                'amount_minor' => (int) $c->amount_minor,
                'reversed_minor' => (int) $c->reversed_minor,
                'net_minor' => (int) $c->amount_minor - (int) $c->reversed_minor,
                'status' => $c->status,
            ];
        }
    }
}
