<?php

namespace App\Domain\Referrals;

use App\Domain\Reporting\Period;
use App\Enums\OrderStatus;
use App\Models\AffiliateLink;
use App\Models\AffiliateProfile;
use App\Models\OrderAttribution;
use App\Models\ReferralClick;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Referral performance for a date range in the business timezone. Placed
 * orders are not sales: they are reported separately from paid revenue,
 * which arrives with commission accounting in Phase 4.
 */
class ReferralReport
{
    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public static function range(?string $from, ?string $to, int $defaultDays = 30): array
    {
        $tz = (string) config('affiliate.business_timezone');
        $end = $to ? CarbonImmutable::parse($to, $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfDay();
        $start = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : $end->subDays($defaultDays - 1)->startOfDay();

        return ['from' => $start, 'to' => $end];
    }

    /**
     * @return array<string, mixed>
     */
    public function forAffiliate(AffiliateProfile $profile, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $clicks = $this->clicks($from, $to)->where('affiliate_profile_id', $profile->id);
        $orders = $this->orders($from, $to)->where('order_attributions.affiliate_profile_id', $profile->id);

        return [
            'totals' => $this->totals(clone $clicks, clone $orders),
            'daily' => $this->daily(clone $clicks, clone $orders, $from, $to),
            'by_link' => $this->byLink($profile, clone $clicks, clone $orders),
            'by_source' => (clone $orders)->whereNot('orders.status', OrderStatus::Cancelled->value)
                ->selectRaw('order_attributions.source as source, count(*) as orders')
                ->groupBy('order_attributions.source')
                ->pluck('orders', 'source')
                ->map(fn ($n) => (int) $n),
        ];
    }

    /**
     * Clicks and click-attributed orders per referral link.
     *
     * @return list<array<string, mixed>>
     */
    public function linkPerformance(AffiliateProfile $profile, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->byLink(
            $profile,
            $this->clicks($from, $to)->where('affiliate_profile_id', $profile->id),
            $this->orders($from, $to)->where('order_attributions.affiliate_profile_id', $profile->id),
        );
    }

    /**
     * Programme-wide totals and the top affiliates, for staff.
     *
     * @return array<string, mixed>
     */
    public function programme(CarbonImmutable $from, CarbonImmutable $to, int $limit = 20): array
    {
        $clickCounts = $this->clicks($from, $to)
            ->selectRaw('affiliate_profile_id, count(*) as clicks, sum(case when is_unique then 1 else 0 end) as unique_clicks')
            ->groupBy('affiliate_profile_id')
            ->get()->keyBy('affiliate_profile_id');

        $orderCounts = $this->orders($from, $to)
            ->whereNot('orders.status', OrderStatus::Cancelled->value)
            ->selectRaw('order_attributions.affiliate_profile_id as affiliate_profile_id, count(*) as orders, sum(orders.subtotal_minor - orders.discount_minor) as order_value')
            ->groupBy('order_attributions.affiliate_profile_id')
            ->get()->keyBy('affiliate_profile_id');

        $ids = $clickCounts->keys()->merge($orderCounts->keys())->unique();
        $profiles = AffiliateProfile::query()->whereIn('id', $ids)->get()->keyBy('id');

        $rows = $ids->map(fn ($id) => [
            'affiliate_id' => $profiles[$id]->public_id,
            'display_name' => $profiles[$id]->display_name,
            'referral_code' => $profiles[$id]->referral_code,
            'clicks' => (int) ($clickCounts[$id]->clicks ?? 0),
            'unique_clicks' => (int) ($clickCounts[$id]->unique_clicks ?? 0),
            'orders' => (int) ($orderCounts[$id]->orders ?? 0),
            'order_value_minor' => (int) ($orderCounts[$id]->order_value ?? 0),
        ])->sortByDesc(fn ($r) => [$r['orders'], $r['clicks']])->take($limit)->values();

        return [
            'totals' => $this->totals($this->clicks($from, $to), $this->orders($from, $to)),
            'top_affiliates' => $rows,
        ];
    }

    /**
     * @return Builder<ReferralClick>
     */
    private function clicks(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return ReferralClick::query()->whereBetween('referral_clicks.created_at', [$from->utc(), $to->utc()]);
    }

    /**
     * @return Builder<OrderAttribution>
     */
    private function orders(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return OrderAttribution::query()
            ->join('orders', 'orders.id', '=', 'order_attributions.order_id')
            ->whereBetween('orders.placed_at', [$from->utc(), $to->utc()]);
    }

    /**
     * @param  Builder<ReferralClick>  $clicks
     * @param  Builder<OrderAttribution>  $orders
     * @return array<string, int|float|null>
     */
    private function totals(Builder $clicks, Builder $orders): array
    {
        $clickTotals = (clone $clicks)->selectRaw('count(*) as clicks, sum(case when is_unique then 1 else 0 end) as unique_clicks')->first();
        $live = (clone $orders)->whereNot('orders.status', OrderStatus::Cancelled->value)
            ->selectRaw('count(*) as orders, sum(orders.subtotal_minor - orders.discount_minor) as value')->first();
        $cancelled = (clone $orders)->where('orders.status', OrderStatus::Cancelled->value)->count();

        $unique = (int) $clickTotals->unique_clicks;
        $orderCount = (int) $live->orders;

        return [
            'clicks' => (int) $clickTotals->clicks,
            'unique_clicks' => $unique,
            'orders' => $orderCount,
            'cancelled_orders' => $cancelled,
            'order_value_minor' => (int) $live->value,
            // Orders per 100 unique clicks; null when there were no clicks.
            'conversion_rate' => $unique > 0 ? round($orderCount / $unique * 100, 2) : null,
        ];
    }

    /**
     * @param  Builder<ReferralClick>  $clicks
     * @param  Builder<OrderAttribution>  $orders
     * @return list<array{date: string, clicks: int, orders: int}>
     */
    private function daily(Builder $clicks, Builder $orders, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $period = Period::between($from, $to);
        $days = [];
        foreach (CarbonPeriod::create($from->startOfDay(), $to->startOfDay()) as $day) {
            $days[$day->format('Y-m-d')] = ['date' => $day->format('Y-m-d'), 'clicks' => 0, 'orders' => 0];
        }

        // Grouped in the database by local (business timezone) day.
        $clickCounts = $clicks->toBase()
            ->selectRaw($period->bucketSql('referral_clicks.created_at', 'day').' as day, COUNT(*) as n')
            ->groupBy('day')->pluck('n', 'day');
        $orderCounts = $orders->toBase()->whereNot('orders.status', OrderStatus::Cancelled->value)
            ->selectRaw($period->bucketSql('orders.placed_at', 'day').' as day, COUNT(*) as n')
            ->groupBy('day')->pluck('n', 'day');

        foreach ($days as $key => $_) {
            $days[$key]['clicks'] = (int) ($clickCounts[$key] ?? 0);
            $days[$key]['orders'] = (int) ($orderCounts[$key] ?? 0);
        }

        return array_values($days);
    }

    /**
     * @param  Builder<ReferralClick>  $clicks
     * @param  Builder<OrderAttribution>  $orders
     * @return list<array<string, mixed>>
     */
    private function byLink(AffiliateProfile $profile, Builder $clicks, Builder $orders): array
    {
        $clickCounts = $clicks->selectRaw('affiliate_link_id, count(*) as clicks, sum(case when is_unique then 1 else 0 end) as unique_clicks')
            ->groupBy('affiliate_link_id')->get()->keyBy(fn ($r) => (string) $r->affiliate_link_id);

        $orderCounts = $orders->whereNot('orders.status', OrderStatus::Cancelled->value)
            ->where('order_attributions.source', 'click')
            ->join('referral_clicks', 'referral_clicks.id', '=', 'order_attributions.referral_click_id')
            ->select('referral_clicks.affiliate_link_id', DB::raw('count(*) as orders'))
            ->groupBy('referral_clicks.affiliate_link_id')
            ->pluck('orders', 'affiliate_link_id');

        $links = AffiliateLink::query()->where('affiliate_profile_id', $profile->id)->get()->keyBy('id');
        $keys = $clickCounts->keys()->merge($orderCounts->keys()->map(fn ($k) => (string) $k))->unique();

        return $keys->map(fn ($key) => [
            'link_id' => $key === '' ? null : $links[(int) $key]?->public_id,
            'name' => $key === '' ? 'Main referral link' : ($links[(int) $key]?->name ?? 'Deleted link'),
            'clicks' => (int) ($clickCounts[$key]->clicks ?? 0),
            'unique_clicks' => (int) ($clickCounts[$key]->unique_clicks ?? 0),
            'orders' => (int) ($orderCounts[$key === '' ? '' : (int) $key] ?? 0),
        ])->sortByDesc('clicks')->values()->all();
    }
}
