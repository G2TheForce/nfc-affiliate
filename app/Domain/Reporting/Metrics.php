<?php

namespace App\Domain\Reporting;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single definition of every dashboard and report figure (ADR 0010).
 * Everything is aggregated in the database; nothing loads rows to count
 * them. Dates are the business timezone (Period).
 *
 * - clicks: recorded referral clicks (bots and rate-limited clicks are never
 *   recorded), by click time. Unique: first click from a visitor in 24 hours.
 * - attributed orders: orders credited to an affiliate, placed in the range,
 *   not cancelled. Includes orders not paid yet.
 * - conversion rate: attributed orders per 100 unique clicks.
 * - revenue: product value after discounts (delivery excluded) of attributed
 *   orders paid in the range, before refunds.
 * - commissionable revenue: the base of commissions on orders paid in the
 *   range; earned: their commission amount; reversed: what refunds and voids
 *   have taken back from those commissions so far.
 * - refunded: product value refunded in the range on attributed orders.
 * - ledger flows (reversed, adjusted, paid out): ledger entries posted in
 *   the range.
 */
class Metrics
{
    /** Plain-language definitions shown next to each figure. */
    public const DEFINITIONS = [
        'clicks' => 'Visits through referral links in the period. Bots and rapid repeat clicks are not counted.',
        'unique_clicks' => 'Clicks from different visitors. The same visitor is counted again after 24 hours.',
        'attributed_orders' => 'Orders credited to an affiliate and placed in the period, paid or not, excluding cancelled orders.',
        'conversion_rate' => 'Attributed orders per 100 unique clicks.',
        'paid_orders' => 'Attributed orders whose payment was confirmed in the period.',
        'revenue_minor' => 'What customers paid for products on attributed orders paid in the period, after discounts, before refunds. Delivery is excluded.',
        'commissionable_minor' => 'The part of that revenue that earns commission (excluded products and non-earning coupons are left out).',
        'commission_earned_minor' => 'Commission created on orders paid in the period.',
        'commission_reversed_minor' => 'How much of that commission refunds or voids have taken back so far.',
        'refunded_minor' => 'Product value refunded in the period on attributed orders.',
        'pending' => 'Commission inside the holding period. Not yet payable.',
        'approved' => 'Holding period passed; waiting to be released to the available balance.',
        'available' => 'Payable now. Can go below zero when a sale is refunded after its commission was paid.',
        'reserved' => 'In a payout request that has not been paid yet.',
        'paid' => 'Paid out to the affiliate, all time.',
        'reversed' => 'Taken back by refunds or voids, all time.',
    ];

    /**
     * @return array<string, int|float|null>
     */
    public function referralTotals(Period $period, Filters $filters): array
    {
        $clicks = $this->clicks($period, $filters)
            ->selectRaw('COUNT(*) as clicks, COALESCE(SUM(CASE WHEN is_unique THEN 1 ELSE 0 END), 0) as unique_clicks')
            ->first();
        $orders = $this->placedOrders($period, $filters)->count();
        $paid = $this->paidItems($period, $filters)
            ->selectRaw('COUNT(DISTINCT orders.id) as orders, COALESCE(SUM(order_items.line_total_minor), 0) as revenue')
            ->first();
        $commissions = $this->commissions($period, $filters)
            ->selectRaw('COALESCE(SUM(commissions.base_minor), 0) as base, COALESCE(SUM(commissions.amount_minor), 0) as earned, COALESCE(SUM(commissions.reversed_minor), 0) as reversed')
            ->first();
        $unique = (int) $clicks->unique_clicks;

        return [
            'clicks' => (int) $clicks->clicks,
            'unique_clicks' => $unique,
            'attributed_orders' => $orders,
            'conversion_rate' => self::rate($orders, $unique),
            'paid_orders' => (int) $paid->orders,
            'revenue_minor' => (int) $paid->revenue,
            'commissionable_minor' => (int) $commissions->base,
            'commission_earned_minor' => (int) $commissions->earned,
            'commission_reversed_minor' => (int) $commissions->reversed,
            'refunded_minor' => $this->refundedOnAttributed($period, $filters),
        ];
    }

    /**
     * Clicks, orders, revenue and earnings per day or month.
     *
     * @return list<array{bucket: string, clicks: int, unique_clicks: int, orders: int, conversion_rate: ?float, revenue_minor: int, earned_minor: int}>
     */
    public function series(Period $period, Filters $filters): array
    {
        $clicks = $this->clicks($period, $filters)
            ->selectRaw($period->bucketSql('referral_clicks.created_at').' as bucket, COUNT(*) as n, COALESCE(SUM(CASE WHEN is_unique THEN 1 ELSE 0 END), 0) as u')
            ->groupBy('bucket')->get()->keyBy('bucket');
        $orders = $this->placedOrders($period, $filters)
            ->selectRaw($period->bucketSql('orders.placed_at').' as bucket, COUNT(*) as n')
            ->groupBy('bucket')->pluck('n', 'bucket');
        $revenue = $this->paidItems($period, $filters)
            ->selectRaw($period->bucketSql('orders.paid_at').' as bucket, SUM(order_items.line_total_minor) as total')
            ->groupBy('bucket')->pluck('total', 'bucket');
        $earned = $this->commissions($period, $filters)
            ->selectRaw($period->bucketSql('orders.paid_at').' as bucket, SUM(commissions.amount_minor) as total')
            ->groupBy('bucket')->pluck('total', 'bucket');

        return array_map(function (string $key) use ($clicks, $orders, $revenue, $earned) {
            $unique = (int) ($clicks[$key]->u ?? 0);
            $orderCount = (int) ($orders[$key] ?? 0);

            return [
                'bucket' => $key,
                'clicks' => (int) ($clicks[$key]->n ?? 0),
                'unique_clicks' => $unique,
                'orders' => $orderCount,
                'conversion_rate' => self::rate($orderCount, $unique),
                'revenue_minor' => (int) ($revenue[$key] ?? 0),
                'earned_minor' => (int) ($earned[$key] ?? 0),
            ];
        }, $period->bucketKeys());
    }

    /**
     * Units, revenue and commission per product, on attributed orders paid
     * in the range.
     *
     * @return list<array<string, mixed>>
     */
    public function products(Period $period, Filters $filters, ?int $limit = 10): array
    {
        $commission = DB::table('commissions')
            ->selectRaw('order_item_id, SUM(amount_minor - reversed_minor) as net')
            ->when($filters->affiliateId, fn ($q, $id) => $q->where('affiliate_profile_id', $id))
            ->groupBy('order_item_id');

        return $this->paidItems($period, $filters)
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoinSub($commission, 'c', 'c.order_item_id', '=', 'order_items.id')
            ->selectRaw('products.public_id as product_id, products.name as name, SUM(order_items.quantity) as units, COUNT(DISTINCT orders.id) as orders,
                SUM(order_items.line_total_minor) as revenue_minor, COALESCE(SUM(c.net), 0) as commission_minor')
            ->groupBy('products.id', 'products.public_id', 'products.name')
            ->orderByDesc('revenue_minor')
            ->when($limit, fn ($q, $n) => $q->limit($n))
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'name' => $r->name,
                'units' => (int) $r->units,
                'orders' => (int) $r->orders,
                'revenue_minor' => (int) $r->revenue_minor,
                'commission_minor' => (int) $r->commission_minor,
            ])->all();
    }

    /**
     * Coupon campaigns: attributed orders placed with each coupon in the
     * range, and what was paid and earned on them.
     *
     * @return list<array<string, mixed>>
     */
    public function campaigns(Period $period, Filters $filters, ?int $limit = 10): array
    {
        $placed = $this->placedOrders($period, $filters)->whereNotNull('orders.coupon_id')
            ->selectRaw('orders.coupon_id, COUNT(*) as orders')->groupBy('orders.coupon_id')->pluck('orders', 'coupon_id');
        $paid = $this->paidItems($period, $filters)->whereNotNull('orders.coupon_id')
            ->selectRaw('orders.coupon_id, SUM(order_items.line_total_minor) as revenue')->groupBy('orders.coupon_id')->pluck('revenue', 'coupon_id');
        $earned = $this->commissions($period, $filters)->whereNotNull('orders.coupon_id')
            ->selectRaw('orders.coupon_id, SUM(commissions.amount_minor - commissions.reversed_minor) as net')->groupBy('orders.coupon_id')->pluck('net', 'coupon_id');

        $ids = $placed->keys()->merge($paid->keys())->merge($earned->keys())->unique()->values();
        $coupons = DB::table('coupons')->leftJoin('affiliate_profiles', 'affiliate_profiles.id', '=', 'coupons.affiliate_profile_id')
            ->whereIn('coupons.id', $ids)->get(['coupons.id', 'coupons.public_id', 'coupons.code', 'affiliate_profiles.display_name'])->keyBy('id');

        return $ids->map(fn ($id) => [
            'coupon_id' => $coupons[$id]->public_id ?? null,
            'code' => $coupons[$id]->code ?? '?',
            'affiliate' => $coupons[$id]->display_name ?? null,
            'orders' => (int) ($placed[$id] ?? 0),
            'revenue_minor' => (int) ($paid[$id] ?? 0),
            'commission_minor' => (int) ($earned[$id] ?? 0),
        ])->sortByDesc(fn ($r) => [$r['revenue_minor'], $r['orders']])
            ->when($limit, fn ($c) => $c->take($limit))
            ->values()->all();
    }

    /**
     * Per affiliate, for staff: clicks, orders, revenue and commission.
     *
     * @return list<array<string, mixed>>
     */
    public function affiliates(Period $period, Filters $filters, ?int $limit = 10): array
    {
        $clicks = $this->clicks($period, $filters)
            ->selectRaw('affiliate_profile_id as id, COUNT(*) as clicks, COALESCE(SUM(CASE WHEN is_unique THEN 1 ELSE 0 END), 0) as unique_clicks')
            ->groupBy('affiliate_profile_id')->get()->keyBy('id');
        $orders = $this->placedOrders($period, $filters)
            ->selectRaw('order_attributions.affiliate_profile_id as id, COUNT(*) as n')
            ->groupBy('order_attributions.affiliate_profile_id')->pluck('n', 'id');
        $revenue = $this->paidItems($period, $filters)
            ->selectRaw('order_attributions.affiliate_profile_id as id, SUM(order_items.line_total_minor) as total')
            ->groupBy('order_attributions.affiliate_profile_id')->pluck('total', 'id');
        $earned = $this->commissions($period, $filters)
            ->selectRaw('commissions.affiliate_profile_id as id, SUM(commissions.amount_minor) as earned, SUM(commissions.reversed_minor) as reversed')
            ->groupBy('commissions.affiliate_profile_id')->get()->keyBy('id');

        $ids = $clicks->keys()->merge($orders->keys())->merge($revenue->keys())->merge($earned->keys())->unique()->values();
        $profiles = DB::table('affiliate_profiles')->whereIn('id', $ids)->get(['id', 'public_id', 'display_name', 'referral_code', 'status'])->keyBy('id');

        return $ids->map(function ($id) use ($clicks, $orders, $revenue, $earned, $profiles) {
            $unique = (int) ($clicks[$id]->unique_clicks ?? 0);
            $orderCount = (int) ($orders[$id] ?? 0);

            return [
                'affiliate_id' => $profiles[$id]->public_id,
                'display_name' => $profiles[$id]->display_name,
                'referral_code' => $profiles[$id]->referral_code,
                'status' => $profiles[$id]->status,
                'clicks' => (int) ($clicks[$id]->clicks ?? 0),
                'unique_clicks' => $unique,
                'orders' => $orderCount,
                'conversion_rate' => self::rate($orderCount, $unique),
                'revenue_minor' => (int) ($revenue[$id] ?? 0),
                'commission_earned_minor' => (int) ($earned[$id]->earned ?? 0),
                'commission_reversed_minor' => (int) ($earned[$id]->reversed ?? 0),
            ];
        })->sortByDesc(fn ($r) => [$r['revenue_minor'], $r['orders'], $r['clicks']])
            ->when($limit, fn ($c) => $c->take($limit))
            ->values()->all();
    }

    /**
     * Ledger movements posted in the range, by kind.
     *
     * @return array<string, int>
     */
    public function ledgerFlows(Period $period, ?int $affiliateId = null): array
    {
        $rows = DB::table('commission_ledger_entries')
            ->whereBetween('created_at', $period->utc())
            ->when($affiliateId, fn ($q, $id) => $q->where('affiliate_profile_id', $id))
            ->selectRaw('type, from_bucket, to_bucket, SUM(amount_minor) as total')
            ->groupBy('type', 'from_bucket', 'to_bucket')
            ->get();
        $sum = fn (callable $match) => (int) $rows->filter($match)->sum('total');

        return [
            'accrued_minor' => $sum(fn ($r) => $r->type === LedgerEntryType::Accrual->value),
            'refund_reversed_minor' => $sum(fn ($r) => $r->type === LedgerEntryType::RefundReversal->value),
            'voided_minor' => $sum(fn ($r) => $r->type === LedgerEntryType::Void->value),
            'adjusted_credit_minor' => $sum(fn ($r) => $r->type === LedgerEntryType::Adjustment->value && $r->to_bucket !== null),
            'adjusted_debit_minor' => $sum(fn ($r) => $r->type === LedgerEntryType::Adjustment->value && $r->from_bucket !== null),
            'released_minor' => $sum(fn ($r) => $r->type === LedgerEntryType::Release->value),
            'payout_requested_minor' => $sum(fn ($r) => $r->type === LedgerEntryType::PayoutReserve->value),
            'payout_returned_minor' => $sum(fn ($r) => $r->type === LedgerEntryType::PayoutRelease->value),
            'paid_out_minor' => $sum(fn ($r) => $r->type === LedgerEntryType::PayoutPaid->value),
        ];
    }

    /**
     * Ledger flows per bucket for the finance chart.
     *
     * @return list<array{bucket: string, accrued_minor: int, reversed_minor: int, paid_out_minor: int}>
     */
    public function ledgerSeries(Period $period): array
    {
        $rows = DB::table('commission_ledger_entries')
            ->whereBetween('created_at', $period->utc())
            ->whereIn('type', [LedgerEntryType::Accrual->value, LedgerEntryType::RefundReversal->value, LedgerEntryType::Void->value, LedgerEntryType::PayoutPaid->value])
            ->selectRaw($period->bucketSql('created_at').' as bucket, type, SUM(amount_minor) as total')
            ->groupBy('bucket', 'type')
            ->get()
            ->groupBy('bucket');

        return array_map(function (string $key) use ($rows) {
            $byType = ($rows[$key] ?? collect())->pluck('total', 'type');

            return [
                'bucket' => $key,
                'accrued_minor' => (int) ($byType[LedgerEntryType::Accrual->value] ?? 0),
                'reversed_minor' => (int) ($byType[LedgerEntryType::RefundReversal->value] ?? 0) + (int) ($byType[LedgerEntryType::Void->value] ?? 0),
                'paid_out_minor' => (int) ($byType[LedgerEntryType::PayoutPaid->value] ?? 0),
            ];
        }, $period->bucketKeys());
    }

    /** Orders per 100 unique clicks, or null without clicks. */
    public static function rate(int $orders, int $uniqueClicks): ?float
    {
        return $uniqueClicks > 0 ? round($orders / $uniqueClicks * 100, 2) : null;
    }

    public function clicks(Period $period, Filters $filters): Builder
    {
        return DB::table('referral_clicks')
            ->whereBetween('referral_clicks.created_at', $period->utc())
            ->when($filters->affiliateId, fn ($q, $id) => $q->where('referral_clicks.affiliate_profile_id', $id));
    }

    /** Attributed, not cancelled, placed in the range. */
    public function placedOrders(Period $period, Filters $filters): Builder
    {
        return $this->attributed($filters)
            ->whereBetween('orders.placed_at', $period->utc())
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->when($filters->productId, fn ($q, $id) => $q->whereExists(
                fn ($e) => $e->selectRaw('1')->from('order_items')->whereColumn('order_items.order_id', 'orders.id')->where('order_items.product_id', $id)
            ));
    }

    /** Order lines of attributed orders paid in the range. */
    public function paidItems(Period $period, Filters $filters): Builder
    {
        return $this->attributed($filters)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.paid_at', $period->utc())
            ->when($filters->productId, fn ($q, $id) => $q->where('order_items.product_id', $id));
    }

    /** Commissions on orders paid in the range. */
    public function commissions(Period $period, Filters $filters): Builder
    {
        return DB::table('commissions')
            ->join('orders', 'orders.id', '=', 'commissions.order_id')
            ->whereBetween('orders.paid_at', $period->utc())
            ->when($filters->affiliateId, fn ($q, $id) => $q->where('commissions.affiliate_profile_id', $id))
            ->when($filters->couponId, fn ($q, $id) => $q->where('orders.coupon_id', $id))
            ->when($filters->productId, fn ($q, $id) => $q->join('order_items', 'order_items.id', '=', 'commissions.order_item_id')->where('order_items.product_id', $id));
    }

    private function refundedOnAttributed(Period $period, Filters $filters): int
    {
        if ($filters->productId) {
            return (int) DB::table('refund_items')
                ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
                ->join('order_items', 'order_items.id', '=', 'refund_items.order_item_id')
                ->join('order_attributions', 'order_attributions.order_id', '=', 'refunds.order_id')
                ->join('orders', 'orders.id', '=', 'refunds.order_id')
                ->whereBetween('refunds.completed_at', $period->utc())
                ->where('order_items.product_id', $filters->productId)
                ->when($filters->affiliateId, fn ($q, $id) => $q->where('order_attributions.affiliate_profile_id', $id))
                ->when($filters->couponId, fn ($q, $id) => $q->where('orders.coupon_id', $id))
                ->sum('refund_items.amount_minor');
        }

        return (int) DB::table('refunds')
            ->join('order_attributions', 'order_attributions.order_id', '=', 'refunds.order_id')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->whereBetween('refunds.completed_at', $period->utc())
            ->when($filters->affiliateId, fn ($q, $id) => $q->where('order_attributions.affiliate_profile_id', $id))
            ->when($filters->couponId, fn ($q, $id) => $q->where('orders.coupon_id', $id))
            ->selectRaw('COALESCE(SUM(refunds.amount_minor - refunds.shipping_minor), 0) as total')
            ->value('total');
    }

    private function attributed(Filters $filters): Builder
    {
        return DB::table('order_attributions')
            ->join('orders', 'orders.id', '=', 'order_attributions.order_id')
            ->when($filters->affiliateId, fn ($q, $id) => $q->where('order_attributions.affiliate_profile_id', $id))
            ->when($filters->couponId, fn ($q, $id) => $q->where('orders.coupon_id', $id));
    }
}
