<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Domain\Marketing\MarketingAssetService;
use App\Http\Controllers\Api\V1\Admin\MarketingAssetController as AdminMarketingAssetController;
use App\Http\Controllers\Controller;
use App\Models\AffiliateProfile;
use App\Models\Campaign;
use App\Models\MarketingAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Active marketing materials for affiliates. Materials tied to a campaign
 * are shown only while it runs and only to affiliates taking part.
 */
class MarketingAssetController extends Controller
{
    use ResolvesAffiliate;

    public function __construct(private readonly MarketingAssetService $assets) {}

    public function index(Request $request): array
    {
        $profile = $this->trackedProfile($request);

        return ['data' => $this->visibleTo($profile)
            ->with(['campaign', 'product'])
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (MarketingAsset $a) => AdminMarketingAssetController::present($a))
            ->all()];
    }

    public function file(Request $request, string $asset): StreamedResponse
    {
        $profile = $this->trackedProfile($request);
        $model = $this->visibleTo($profile)->where('public_id', $asset)->firstOrFail();
        $inline = $request->boolean('inline');

        if (! $inline) {
            $this->assets->countDownload($model);
        }

        return $this->assets->respond($model, $inline);
    }

    /** @return Builder<MarketingAsset> */
    private function visibleTo(AffiliateProfile $profile): Builder
    {
        return MarketingAsset::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->whereNull('campaign_id')
                ->orWhereIn('campaign_id', Campaign::query()->runningAt(now())->openTo($profile)->select('id')));
    }
}
