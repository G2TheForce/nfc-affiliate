<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliate\ReferralLinkRequest;
use App\Http\Resources\AffiliateLinkResource;
use App\Models\AffiliateLink;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReferralLinkController extends Controller
{
    use ResolvesAffiliate;

    private const MAX_LINKS = 100;

    public function index(Request $request): array
    {
        $profile = $this->trackedProfile($request);
        $links = $profile->links()->with(['product', 'affiliate'])->get();

        return ['data' => [
            'referral_code' => $profile->referral_code,
            'referral_slug' => $profile->referral_slug,
            'main_url' => url('/ref/'.$profile->referral_code),
            'can_create' => $profile->status->isEligibleToEarn(),
            'attribution' => [
                'policy' => config('affiliate.attribution.policy'),
                'window_days' => (int) config('affiliate.attribution.window_days'),
                'coupon_overrides_click' => (bool) config('affiliate.attribution.coupon_overrides_click'),
            ],
            'links' => AffiliateLinkResource::collection($links),
        ]];
    }

    public function store(ReferralLinkRequest $request): JsonResponse
    {
        $profile = $this->earningProfile($request);
        $data = $request->validated();

        if ($profile->links()->count() >= self::MAX_LINKS) {
            throw ValidationException::withMessages(['name' => 'You can have at most '.self::MAX_LINKS.' tracking links.']);
        }
        if ($profile->links()->where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages(['slug' => 'You already have a link with this address.']);
        }

        $link = new AffiliateLink($this->attributes($data));
        $link->affiliate_profile_id = $profile->id;
        $link->save();

        return (new AffiliateLinkResource($link->load(['product', 'affiliate'])))->response()->setStatusCode(201);
    }

    public function update(ReferralLinkRequest $request, string $link): AffiliateLinkResource
    {
        $profile = $this->earningProfile($request);
        $model = $profile->links()->where('public_id', $link)->firstOrFail();

        $model->fill($this->attributes($request->validated()))->save();

        return new AffiliateLinkResource($model->load(['product', 'affiliate']));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        if (array_key_exists('product_id', $data)) {
            $data['product_id'] = $data['product_id'] ? Product::where('public_id', $data['product_id'])->value('id') : null;
        }

        return $data;
    }
}
