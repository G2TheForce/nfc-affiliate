<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Marketing\MarketingAssetService;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\MarketingAsset;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The marketing library (marketing_assets.manage). */
class MarketingAssetController extends Controller
{
    public function __construct(private readonly MarketingAssetService $assets) {}

    public function index(Request $request): array
    {
        $filters = $request->validate([
            'campaign_id' => ['nullable', 'string', 'ulid'],
            'active' => ['nullable', 'boolean'],
        ]);

        $assets = MarketingAsset::query()
            ->with(['campaign', 'product'])
            ->when($filters['campaign_id'] ?? null, fn ($q, $id) => $q->whereHas('campaign', fn ($c) => $c->where('public_id', $id)))
            ->when(array_key_exists('active', $filters) && $filters['active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['active']))
            ->latest('id')
            ->paginate(50);

        return [
            'data' => $assets->getCollection()->map(fn (MarketingAsset $a) => self::present($a, staff: true))->all(),
            'meta' => ['current_page' => $assets->currentPage(), 'last_page' => $assets->lastPage(), 'total' => $assets->total()],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(creating: true));
        $asset = $this->assets->create($this->attributes($data), $request->file('file'), $request->user());

        return response()->json(['data' => self::present($asset->load(['campaign', 'product']), staff: true)], 201);
    }

    public function update(Request $request, MarketingAsset $asset): array
    {
        $data = $request->validate($this->rules(creating: false));
        $asset = $this->assets->update($asset, $this->attributes($data), $request->user());

        return ['data' => self::present($asset->load(['campaign', 'product']), staff: true)];
    }

    public function destroy(Request $request, MarketingAsset $asset): Response
    {
        $this->assets->delete($asset, $request->user());

        return response()->noContent();
    }

    public function file(Request $request, MarketingAsset $asset): StreamedResponse
    {
        return $this->assets->respond($asset, $request->boolean('inline'));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'campaign_id' => ['nullable', 'string', 'ulid', Rule::exists('campaigns', 'public_id')],
            'product_id' => ['nullable', 'string', 'ulid', Rule::exists('products', 'public_id')],
            'is_active' => ['sometimes', 'boolean'],
            // A file cannot be replaced; upload a new asset and remove the old one.
            'file' => $creating ? ['nullable', 'file', 'max:'.(int) config('affiliate.marketing.max_upload_kb')] : ['prohibited'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        if (array_key_exists('campaign_id', $data)) {
            $data['campaign_id'] = $data['campaign_id'] ? Campaign::where('public_id', $data['campaign_id'])->value('id') : null;
        }
        if (array_key_exists('product_id', $data)) {
            $data['product_id'] = $data['product_id'] ? Product::where('public_id', $data['product_id'])->value('id') : null;
        }
        unset($data['file']);

        return $data;
    }

    /** @return array<string, mixed> */
    public static function present(MarketingAsset $asset, bool $staff = false): array
    {
        $data = [
            'id' => $asset->public_id,
            'title' => $asset->title,
            'description' => $asset->description,
            'kind' => $asset->kind,
            'caption' => $asset->caption,
            'has_file' => $asset->hasFile(),
            'mime' => $asset->mime,
            'size_bytes' => $asset->size_bytes,
            'campaign' => $asset->campaign ? ['id' => $asset->campaign->public_id, 'name' => $asset->campaign->name] : null,
            'product' => $asset->product ? ['id' => $asset->product->public_id, 'name' => $asset->product->name, 'slug' => $asset->product->slug] : null,
            'created_at' => $asset->created_at?->toIso8601String(),
        ];

        return $staff ? $data + [
            'is_active' => $asset->is_active,
            'original_name' => $asset->original_name,
            'download_count' => $asset->download_count,
        ] : $data;
    }
}
