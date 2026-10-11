<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Domain\Commissions\CommissionLedger;
use App\Domain\Commissions\CommissionMath;
use App\Domain\Commissions\RuleResolver;
use App\Enums\CommissionRuleKind;
use App\Enums\CommissionRuleScope;
use App\Enums\CommissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commissions\CommissionResource;
use App\Http\Resources\Commissions\LedgerEntryResource;
use App\Models\CommissionRule;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The member's own earnings. Always scoped to the signed-in user's profile;
 * nothing the client sends selects another affiliate.
 */
class EarningsController extends Controller
{
    use ResolvesAffiliate;

    public function summary(Request $request, CommissionLedger $ledger): array
    {
        $profile = $this->trackedProfile($request);
        $counts = $profile->commissions()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return ['data' => [
            'currency' => config('affiliate.currency.default'),
            'balances' => $ledger->balances($profile->id),
            'counts' => collect(CommissionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
            'on_hold_count' => $profile->commissions()->whereNotNull('held_at')->whereIn('status', [CommissionStatus::Pending->value, CommissionStatus::Approved->value])->count(),
            'holding_period_days' => (int) config('affiliate.commissions.holding_period_days'),
            'next_eligible_at' => $profile->commissions()->where('status', CommissionStatus::Pending->value)->whereNull('held_at')->min('eligible_at'),
        ]];
    }

    public function commissions(Request $request): AnonymousResourceCollection
    {
        $profile = $this->trackedProfile($request);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(CommissionStatus::class)]]);

        return CommissionResource::collection(
            $profile->commissions()
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->latest('id')
                ->paginate(25)
                ->withQueryString()
        );
    }

    public function ledger(Request $request): AnonymousResourceCollection
    {
        $profile = $this->trackedProfile($request);

        return LedgerEntryResource::collection(
            $profile->ledgerEntries()->with(['commission', 'adjustment'])->latest('id')->paginate(50)
        );
    }

    /**
     * The rates that apply to this member today: their default, and every
     * product with its own rate or exclusion.
     */
    public function rates(Request $request, RuleResolver $resolver): array
    {
        $profile = $this->earningProfile($request);
        $candidates = $resolver->candidates($profile, now());
        $default = $candidates->first(fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Affiliate)
            ?? $candidates->first(fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Plan)
            ?? $candidates->first(fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Global);

        $products = Product::query()->active()->orderBy('name')->get()->map(function (Product $product) use ($resolver, $candidates) {
            $rule = $resolver->pick($candidates, $product->id, $product->category_id);
            $excluded = ! $rule || $rule->kind === CommissionRuleKind::Exclude || ($rule->kind === CommissionRuleKind::Fixed && $rule->currency !== $product->currency);

            return [
                'product_id' => $product->public_id,
                'name' => $product->name,
                'price_minor' => $product->price_minor,
                'currency' => $product->currency,
                'earns' => ! $excluded,
                'kind' => $excluded ? null : $rule->kind->value,
                'rate_bp' => $excluded ? null : $rule->rate_bp,
                'fixed_minor' => $excluded ? null : $rule->fixed_minor,
                'commission_per_unit_minor' => $excluded ? 0 : CommissionMath::amount($rule->kind, $product->price_minor, 1, $rule->rate_bp, $rule->fixed_minor),
            ];
        });

        return ['data' => [
            'default' => $default ? ['kind' => $default->kind->value, 'rate_bp' => $default->rate_bp, 'fixed_minor' => $default->fixed_minor, 'currency' => $default->currency] : null,
            'products' => $products,
            'notes' => [
                'Commission is calculated on what the customer paid for the product after discounts. Delivery charges are not included.',
                'Commission starts as pending when the order is paid, and becomes available '.config('affiliate.commissions.holding_period_days').' days later if the order was not refunded.',
                'Refunds reverse the commission on the refunded part of the order.',
            ],
        ]];
    }
}
