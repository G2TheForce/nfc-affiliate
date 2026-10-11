<?php

namespace Tests\Feature\Reporting;

use App\Domain\Commissions\CommissionLedger;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;
    use ReportingTestHelpers;

    private const RANGE = '?from=2026-09-01&to=2026-09-30';

    public function test_affiliate_dashboard_figures_match_the_database(): void
    {
        $s = $this->scenario();

        $data = $this->actingAs($s['a']->user)->getJson('/api/v1/affiliate/dashboard'.self::RANGE)->assertOk()->json('data');
        $t = $data['totals'];

        $this->assertSame(3, $t['clicks']);
        $this->assertSame(2, $t['unique_clicks']);
        $this->assertSame(3, $t['attributed_orders']);
        $this->assertSame(150.0, (float) $t['conversion_rate']);
        $this->assertSame(2, $t['paid_orders']);
        $this->assertSame(200000 + 90000, $t['revenue_minor']);
        $this->assertSame(29000, $t['commission_earned_minor']);
        $this->assertSame(10000, $t['commission_reversed_minor']);
        $this->assertSame(100000, $t['refunded_minor']);

        // Cross-checks against the underlying tables.
        $this->assertSame(DB::table('referral_clicks')->where('affiliate_profile_id', $s['a']->id)->count(), $t['clicks']);
        $this->assertSame((int) DB::table('commissions')->where('affiliate_profile_id', $s['a']->id)->sum('amount_minor'), $t['commission_earned_minor']);
        $this->assertSame(app(CommissionLedger::class)->balances($s['a']->id), $data['balances']);

        // Series adds up to the totals; products and campaigns to revenue.
        $series = collect($data['series']);
        $this->assertCount(30, $series);
        $this->assertSame($t['clicks'], $series->sum('clicks'));
        $this->assertSame($t['attributed_orders'], $series->sum('orders'));
        $this->assertSame($t['revenue_minor'], $series->sum('revenue_minor'));
        $this->assertSame($t['commission_earned_minor'], $series->sum('earned_minor'));
        $this->assertSame($t['revenue_minor'], collect($data['products'])->sum('revenue_minor'));
        $this->assertSame([['code' => 'ASHA10', 'orders' => 1, 'revenue_minor' => 90000, 'commission_minor' => 9000]],
            collect($data['campaigns'])->map(fn ($c) => array_intersect_key($c, array_flip(['code', 'orders', 'revenue_minor', 'commission_minor'])))->all());
    }

    public function test_affiliate_sees_only_their_own_figures(): void
    {
        $s = $this->scenario();

        $data = $this->actingAs($s['b']->user)->getJson('/api/v1/affiliate/dashboard'.self::RANGE.'&affiliate_id='.$s['a']->public_id)->assertOk()->json('data');

        $this->assertSame(1, $data['totals']['clicks']);
        $this->assertSame(100000, $data['totals']['revenue_minor']);
        $this->assertSame([], $data['campaigns']);
        $this->assertSame(['Metal card'], collect($data['products'])->pluck('name')->all());
    }

    public function test_days_follow_nepal_time(): void
    {
        $this->travelTo('2026-09-01 04:00:00');
        $this->rule(['rate_bp' => 1000]);
        $a = $this->approvedAffiliate();
        $order = $this->order($a, $this->product(['price_minor' => 100000]), 1);

        // 20:00 UTC is 01:45 the next morning in Kathmandu.
        $this->travelTo('2026-09-01 20:00:00');
        $this->pay($order)->assertCreated();
        $this->clicks($a, 1, 1, '2026-09-01 18:14:00'); // 23:59 local, still the 1st

        $series = collect($this->actingAs($a->user)->getJson('/api/v1/affiliate/dashboard'.self::RANGE)->json('data.series'))->keyBy('bucket');
        $this->assertSame(1, $series['2026-09-01']['clicks']);
        $this->assertSame(1, $series['2026-09-01']['orders']);
        $this->assertSame(0, $series['2026-09-01']['revenue_minor']);
        $this->assertSame(100000, $series['2026-09-02']['revenue_minor']);
    }

    public function test_long_ranges_use_months_and_ranges_are_limited(): void
    {
        $s = $this->scenario();

        $series = $this->actingAs($s['a']->user)->getJson('/api/v1/affiliate/dashboard?from=2026-01-01&to=2026-12-31')->assertOk()->json('data.series');
        $this->assertCount(12, $series);
        $this->assertSame('2026-09', $series[8]['bucket']);
        $this->assertSame(3, $series[8]['clicks']);

        $this->getJson('/api/v1/affiliate/dashboard?from=2025-01-01&to=2026-06-01')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/v1/affiliate/dashboard?from=2026-09-10&to=2026-09-01')->assertUnprocessable();
    }

    public function test_admin_dashboard_totals_and_filters(): void
    {
        $s = $this->scenario();
        $manager = $this->userWithRole(Role::AffiliateManager);

        $data = $this->actingAs($manager)->getJson('/api/v1/admin/dashboard'.self::RANGE)->assertOk()->json('data');
        $this->assertSame(4, $data['totals']['clicks']);
        $this->assertSame(4, $data['totals']['attributed_orders']);
        $this->assertSame(390000, $data['totals']['revenue_minor']);
        $this->assertSame(39000, $data['totals']['commission_earned_minor']);
        $this->assertSame(2, $data['affiliates']['active']);
        $this->assertSame([$s['a']->public_id, $s['b']->public_id], collect($data['top_affiliates'])->pluck('affiliate_id')->all());
        $this->assertSame(app(CommissionLedger::class)->balances(), $data['liabilities']);
        $this->assertSame(10000, $data['refund_reversals_minor']);
        $this->assertArrayNotHasKey('recent_events', $data, 'Affiliate managers cannot read the audit log.');

        // Filters narrow every figure the same way.
        $byProduct = $this->getJson('/api/v1/admin/dashboard'.self::RANGE.'&product_id='.$s['stand']->public_id)->json('data.totals');
        $this->assertSame(2, $byProduct['attributed_orders']);
        $this->assertSame(90000, $byProduct['revenue_minor']);
        $this->assertSame(9000, $byProduct['commission_earned_minor']);
        $byCoupon = $this->getJson('/api/v1/admin/dashboard'.self::RANGE.'&coupon_id='.$s['coupon']->public_id)->json('data.totals');
        $this->assertSame(1, $byCoupon['attributed_orders']);
        $byAffiliate = $this->getJson('/api/v1/admin/dashboard'.self::RANGE.'&affiliate_id='.$s['b']->public_id)->json('data');
        $this->assertSame(1, $byAffiliate['totals']['clicks']);
        $this->assertSame(app(CommissionLedger::class)->balances($s['b']->id), $byAffiliate['liabilities']);

        $this->getJson('/api/v1/admin/dashboard?affiliate_id=01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertUnprocessable();

        $admin = $this->userWithRole(Role::SuperAdmin);
        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard'.self::RANGE)->assertOk()->assertJsonStructure(['data' => ['recent_events']]);
    }

    public function test_finance_dashboard_balances_and_flows_match_the_ledger(): void
    {
        $this->scenario();
        $finance = $this->finance();

        $data = $this->actingAs($finance)->getJson('/api/v1/admin/finance/dashboard'.self::RANGE)->assertOk()->json('data');
        $this->assertSame(app(CommissionLedger::class)->balances(), $data['balances']);
        $this->assertSame((int) DB::table('commission_ledger_entries')->where('type', 'accrual')->sum('amount_minor'), $data['flows']['accrued_minor']);
        $this->assertSame(10000, $data['flows']['refund_reversed_minor']);
        $this->assertSame($data['flows']['accrued_minor'], collect($data['series'])->sum('accrued_minor'));
        $this->assertSame(['count' => 1, 'amount_minor' => 100000], $data['refunds']);
        $this->assertSame(0, $data['payouts']['awaiting_review']['count']);
    }

    public function test_access(): void
    {
        $s = $this->scenario();

        $this->actingAs($this->userWithRole(Role::Support))->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->actingAs($this->userWithRole(Role::Support))->getJson('/api/v1/admin/finance/dashboard')->assertForbidden();
        $this->actingAs($s['a']->user)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->actingAs($this->userWithRole(Role::Customer))->getJson('/api/v1/affiliate/dashboard')->assertForbidden();
    }
}
