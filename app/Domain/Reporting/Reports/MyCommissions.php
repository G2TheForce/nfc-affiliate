<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Metrics;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Support\Privacy\Mask;

/** Affiliate's own commissions. Order numbers are masked as on screen. */
class MyCommissions extends Report
{
    public function __construct(private readonly Metrics $metrics) {}

    public function key(): string
    {
        return 'my-commissions';
    }

    public function title(): string
    {
        return 'My commissions';
    }

    public function description(): string
    {
        return 'Commission on your referred orders paid in the period, with any refund reversal and current status.';
    }

    public function permission(): null
    {
        return null;
    }

    public function filters(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new Column('paid_at', 'Order paid', 'datetime'),
            new Column('order', 'Order'),
            new Column('product', 'Product'),
            new Column('quantity', 'Quantity', 'int'),
            new Column('amount_minor', 'Commission', 'money'),
            new Column('reversed_minor', 'Reversed', 'money'),
            new Column('net_minor', 'Net', 'money'),
            new Column('status', 'Status'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        $query = $this->metrics->commissions($period, $filters)
            ->join('order_items as line', 'line.id', '=', 'commissions.order_item_id')
            ->select('commissions.id', 'orders.paid_at', 'orders.order_number', 'line.name', 'commissions.quantity', 'commissions.amount_minor', 'commissions.reversed_minor', 'commissions.status');

        foreach ($query->lazyById(500, 'commissions.id', 'id') as $c) {
            yield [
                'paid_at' => $c->paid_at,
                'order' => Mask::orderNumber($c->order_number),
                'product' => $c->name,
                'quantity' => (int) $c->quantity,
                'amount_minor' => (int) $c->amount_minor,
                'reversed_minor' => (int) $c->reversed_minor,
                'net_minor' => (int) $c->amount_minor - (int) $c->reversed_minor,
                'status' => $c->status,
            ];
        }
    }
}
