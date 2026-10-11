<?php

namespace App\Domain\Commissions;

use App\Enums\CommissionRuleKind;
use App\Enums\CommissionRuleScope;
use App\Models\AffiliateProfile;
use App\Models\CommissionRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Picks the one rule that applies to an order line (ADR 0008). First match
 * wins, and exclusions always win:
 *
 *   1. product exclusion, then category exclusion
 *   2. affiliate override
 *   3. product rate
 *   4. category rate
 *   5. the affiliate's plan default
 *   6. global default
 *
 * Campaign overrides will slot in at step 2 when campaigns are built.
 * Overlapping rules for the same target are rejected when saved, so each
 * step has at most one candidate.
 */
class RuleResolver
{
    /**
     * Loads every rule that could apply to this affiliate at that moment.
     *
     * @return Collection<int, CommissionRule>
     */
    public function candidates(AffiliateProfile $affiliate, CarbonInterface $at): Collection
    {
        return CommissionRule::query()
            ->inForceAt($at)
            ->where(fn ($q) => $q
                ->where('scope', CommissionRuleScope::Global->value)
                ->orWhereIn('scope', [CommissionRuleScope::Product->value, CommissionRuleScope::Category->value])
                ->orWhere(fn ($q) => $q->where('scope', CommissionRuleScope::Affiliate->value)->where('affiliate_profile_id', $affiliate->id))
                ->when($affiliate->commission_plan_id, fn ($q, $planId) => $q
                    ->orWhere(fn ($q) => $q->where('scope', CommissionRuleScope::Plan->value)->where('commission_plan_id', $planId))))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Collection<int, CommissionRule>  $candidates
     */
    public function pick(Collection $candidates, ?int $productId, ?int $categoryId): ?CommissionRule
    {
        $forProduct = fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Product && $productId && $r->product_id === $productId;
        $forCategory = fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Category && $categoryId && $r->product_category_id === $categoryId;
        $excludes = fn (CommissionRule $r) => $r->kind === CommissionRuleKind::Exclude;

        $steps = [
            fn (CommissionRule $r) => $forProduct($r) && $excludes($r),
            fn (CommissionRule $r) => $forCategory($r) && $excludes($r),
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
