<?php

namespace App\Domain\Commissions;

use App\Enums\CommissionRuleKind;
use App\Enums\CommissionRuleScope;
use App\Models\AffiliateProfile;
use App\Models\Campaign;
use App\Models\CommissionRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Picks the one rule that applies to an order line (ADR 0008). First match
 * wins, and exclusions always win:
 *
 *   1. product exclusion, then category exclusion
 *   2. campaign rate, when the order came through a campaign that covers the product
 *   3. affiliate override
 *   4. product rate
 *   5. category rate
 *   6. the affiliate's plan default
 *   7. global default
 *
 * Overlapping rules for the same target are rejected when saved, so each
 * step has at most one candidate.
 */
class RuleResolver
{
    /**
     * Loads every rule that could apply to this affiliate at that moment.
     * Campaign rules are included only for the order's campaign, and only
     * while it runs and the affiliate takes part (callers check that).
     *
     * @return Collection<int, CommissionRule>
     */
    public function candidates(AffiliateProfile $affiliate, CarbonInterface $at, ?Campaign $campaign = null): Collection
    {
        return CommissionRule::query()
            ->inForceAt($at)
            ->where(fn ($q) => $q
                ->where('scope', CommissionRuleScope::Global->value)
                ->orWhereIn('scope', [CommissionRuleScope::Product->value, CommissionRuleScope::Category->value])
                ->orWhere(fn ($q) => $q->where('scope', CommissionRuleScope::Affiliate->value)->where('affiliate_profile_id', $affiliate->id))
                ->when($affiliate->commission_plan_id, fn ($q, $planId) => $q
                    ->orWhere(fn ($q) => $q->where('scope', CommissionRuleScope::Plan->value)->where('commission_plan_id', $planId)))
                ->when($campaign, fn ($q) => $q
                    ->orWhere(fn ($q) => $q->where('scope', CommissionRuleScope::Campaign->value)->where('campaign_id', $campaign->id))))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Collection<int, CommissionRule>  $candidates
     * @param  list<int>|null  $campaignProductIds  products the campaign covers; null means all
     */
    public function pick(Collection $candidates, ?int $productId, ?int $categoryId, ?array $campaignProductIds = null): ?CommissionRule
    {
        $forProduct = fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Product && $productId && $r->product_id === $productId;
        $forCategory = fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Category && $categoryId && $r->product_category_id === $categoryId;
        $excludes = fn (CommissionRule $r) => $r->kind === CommissionRuleKind::Exclude;
        $campaignCovers = $campaignProductIds === null || ($productId && in_array($productId, $campaignProductIds, true));

        $steps = [
            fn (CommissionRule $r) => $forProduct($r) && $excludes($r),
            fn (CommissionRule $r) => $forCategory($r) && $excludes($r),
            fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Campaign && $campaignCovers,
            fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Affiliate,
            fn (CommissionRule $r) => $forProduct($r),
            fn (CommissionRule $r) => $forCategory($r),
            fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Plan,
            fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Global,
        ];

        foreach ($steps as $matches) {
            if ($rule = $candidates->first($matches)) {
                return $rule;
            }
        }

        return null;
    }
}
