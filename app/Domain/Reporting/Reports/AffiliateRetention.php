<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Monthly activity: an affiliate is active in a month when at least one
 * attributed order (not cancelled) was placed. Retained: active in the
 * previous month too.
 */
class AffiliateRetention extends Report
{
    public function key(): string
    {
        return 'affiliate-retention';
    }

    public function title(): string
    {
        return 'Affiliate retention';
    }

    public function description(): string
    {
        return 'Active, newly active and retained affiliates per month. Active means at least one attributed order that month.';
    }

    public function permission(): Permission
    {
        return Permission::ReportsView;
    }

    public function filters(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new Column('month', 'Month'),
            new Column('active', 'Active affiliates', 'int'),
            new Column('first_time', 'Active for the first time', 'int'),
            new Column('retained', 'Also active the month before', 'int'),
            new Column('retention_rate', 'Retention %', 'percent'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        $start = $period->from->startOfMonth()->subMonth();
        $monthly = Period::between($start, $period->to);
        $bucket = $monthly->bucketSql('orders.placed_at', 'month');

        $pairs = DB::table('order_attributions')
            ->join('orders', 'orders.id', '=', 'order_attributions.order_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.placed_at', $monthly->utc())
            ->selectRaw("DISTINCT order_attributions.affiliate_profile_id as affiliate, {$bucket} as month")
            ->get()
            ->groupBy('month')
            ->map(fn ($rows) => $rows->pluck('affiliate')->map(fn ($id) => (int) $id)->all());

        $first = DB::table('order_attributions')
            ->join('orders', 'orders.id', '=', 'order_attributions.order_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('order_attributions.affiliate_profile_id as affiliate, MIN(orders.placed_at) as first_at')
            ->groupBy('order_attributions.affiliate_profile_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->affiliate => CarbonImmutable::parse($r->first_at, 'UTC')->setTimezone(Period::timezone())->format('Y-m')]);

        foreach (CarbonPeriod::create($period->from->startOfMonth(), '1 month', $period->to->startOfMonth()) as $month) {
            $key = $month->format('Y-m');
            $previous = $month->copy()->subMonth()->format('Y-m');
            $active = $pairs[$key] ?? [];
            $before = $pairs[$previous] ?? [];
            $retained = count(array_intersect($active, $before));

            yield [
                'month' => $key,
                'active' => count($active),
                'first_time' => count(array_filter($active, fn ($id) => ($first[$id] ?? null) === $key)),
                'retained' => $retained,
                'retention_rate' => count($before) > 0 ? round($retained / count($before) * 100, 2) : null,
            ];
        }
    }
}
