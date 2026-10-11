<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Support\Privacy\Mask;
use Illuminate\Support\Facades\DB;

/** Affiliate's referred orders: no customer details, masked order numbers. */
class MyOrders extends Report
{
    public function key(): string
    {
        return 'my-orders';
    }

    public function title(): string
    {
        return 'My referred orders';
    }

    public function description(): string
    {
        return 'Orders credited to you and placed in the period, including cancelled ones. No customer details are included.';
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
            new Column('placed_at', 'Placed', 'datetime'),
            new Column('order', 'Order'),
            new Column('status', 'Status'),
            new Column('value_minor', 'Product value', 'money'),
            new Column('source', 'Credited through'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        $query = DB::table('order_attributions')
            ->join('orders', 'orders.id', '=', 'order_attributions.order_id')
            ->where('order_attributions.affiliate_profile_id', $filters->affiliateId)
            ->whereBetween('orders.placed_at', $period->utc())
            ->select('orders.id', 'orders.placed_at', 'orders.order_number', 'orders.status', 'orders.subtotal_minor', 'orders.discount_minor', 'order_attributions.source');

        foreach ($query->lazyById(500, 'orders.id', 'id') as $o) {
            yield [
                'placed_at' => $o->placed_at,
                'order' => Mask::orderNumber($o->order_number),
                'status' => $o->status,
                'value_minor' => (int) $o->subtotal_minor - (int) $o->discount_minor,
                'source' => $o->source,
            ];
        }
    }
}
