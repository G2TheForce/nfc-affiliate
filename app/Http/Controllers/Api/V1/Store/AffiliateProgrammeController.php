<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Enums\CommissionRuleKind;
use App\Enums\CommissionRuleScope;
use App\Enums\PayoutMethodType;
use App\Http\Controllers\Controller;
use App\Models\AffiliateTermsVersion;
use App\Models\CommissionRule;

/**
 * Public facts for the affiliate programme page: how commission, holding
 * and payouts work, from the live configuration and rules, and the
 * current published terms. Nothing here is per-affiliate.
 */
class AffiliateProgrammeController extends Controller
{
    public function show(): array
    {
        $currency = (string) config('affiliate.currency.default');
        $standard = CommissionRule::query()->inForceAt(now())
            ->where('scope', CommissionRuleScope::Global->value)
            ->where('kind', '!=', CommissionRuleKind::Exclude->value)
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->first();
        $terms = AffiliateTermsVersion::current();

        return ['data' => [
            'currency' => $currency,
            'standard_rate' => $standard ? [
                'kind' => $standard->kind->value,
                'rate_bp' => $standard->kind === CommissionRuleKind::Percent ? $standard->rate_bp : null,
                'fixed_minor' => $standard->kind === CommissionRuleKind::Fixed ? $standard->fixed_minor : null,
            ] : null,
            'attribution_window_days' => (int) config('affiliate.attribution.window_days'),
            'holding_period_days' => (int) config('affiliate.commissions.holding_period_days'),
            'payout_minimum_minor' => (int) config('affiliate.payouts.minimum_minor'),
            'payout_methods' => array_map(fn (PayoutMethodType $t) => $t->label(), PayoutMethodType::cases()),
            'terms' => $terms ? [
                'version' => $terms->version,
                'title' => $terms->title,
                'body' => $terms->body,
                'published_at' => $terms->published_at->toIso8601String(),
            ] : null,
        ]];
    }
}
