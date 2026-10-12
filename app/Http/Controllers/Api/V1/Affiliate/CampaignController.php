<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Enums\CommissionRuleScope;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CommissionRule;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\Request;

/** Campaigns running now that this affiliate takes part in. */
class CampaignController extends Controller
{
    use ResolvesAffiliate;

    public function index(Request $request): array
    {
        $profile = $this->trackedProfile($request);
        $now = now();

        $campaigns = Campaign::query()
            ->runningAt($now)
            ->openTo($profile)
            ->with([
                'products',
                'rules' => fn ($q) => $q->inForceAt($now)->where('scope', CommissionRuleScope::Campaign->value),
                'coupons' => fn ($q) => $q->where('affiliate_profile_id', $profile->id)->where('is_active', true),
            ])
            ->withCount(['links' => fn ($q) => $q->where('affiliate_profile_id', $profile->id)])
            ->orderBy('ends_at')->orderBy('id')
            ->get();

        return ['data' => $campaigns->map(function (Campaign $c) {
            /** @var CommissionRule|null $rule */
            $rule = $c->rules->first();

            return [
                'id' => $c->public_id,
                'name' => $c->name,
                'description' => $c->description,
                'starts_at' => $c->starts_at->toIso8601String(),
                'ends_at' => $c->ends_at?->toIso8601String(),
                'all_products' => $c->products->isEmpty(),
                'products' => $c->products->map(fn (Product $p) => ['id' => $p->public_id, 'name' => $p->name, 'slug' => $p->slug])->values(),
                'commission' => $rule ? ['kind' => $rule->kind->value, 'rate_bp' => $rule->rate_bp, 'fixed_minor' => $rule->fixed_minor, 'currency' => $rule->currency] : null,
                'coupons' => $c->coupons->map(fn (Coupon $k) => ['code' => $k->code, 'discount_type' => $k->discount_type->value, 'discount_value' => $k->discount_value])->values(),
                'my_links' => (int) $c->links_count,
            ];
        })->all()];
    }
}
