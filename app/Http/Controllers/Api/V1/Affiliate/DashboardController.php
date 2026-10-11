<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Domain\Commissions\CommissionLedger;
use App\Domain\Referrals\ReferralReport;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Metrics;
use App\Domain\Reporting\Period;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The member's own dashboard. Every figure comes from Metrics with the
 * member's profile as the only filter; nothing the client sends can widen it.
 */
class DashboardController extends Controller
{
    use ResolvesAffiliate;

    public function __invoke(Request $request, Metrics $metrics, CommissionLedger $ledger, ReferralReport $referrals): array
    {
        $profile = $this->trackedProfile($request);
        $period = self::period($request);
        $filters = Filters::forAffiliate($profile->id);

        return ['data' => [
            'period' => $period->describe(),
            'currency' => config('affiliate.currency.default'),
            'totals' => $metrics->referralTotals($period, $filters),
            'balances' => $ledger->balances($profile->id),
            'series' => $metrics->series($period, $filters),
            'products' => $metrics->products($period, $filters),
            'campaigns' => $metrics->campaigns($period, $filters),
            'links' => $referrals->linkPerformance($profile, $period->from, $period->to),
            'referral_code' => $profile->referral_code,
            'definitions' => Metrics::DEFINITIONS,
        ]];
    }

    public static function period(Request $request): Period
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return Period::fromInput($data['from'] ?? null, $data['to'] ?? null);
    }
}
