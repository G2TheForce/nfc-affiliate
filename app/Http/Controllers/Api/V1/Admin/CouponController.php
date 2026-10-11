<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Http\Resources\Admin\CouponResource;
use App\Models\AffiliateProfile;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:40']]);

        return CouponResource::collection(
            Coupon::query()
                ->with(['affiliate', 'products'])
                ->withCount(['redemptions as active_redemptions_count' => fn ($q) => $q->whereNull('voided_at')])
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('code', 'like', '%'.addcslashes(strtoupper($s), '%_\\').'%'))
                ->latest('id')
                ->paginate(50)
        );
    }

    public function store(CouponRequest $request): JsonResponse
    {
        $coupon = DB::transaction(function () use ($request) {
            $coupon = new Coupon($this->attributes($request->validated()));
            $coupon->created_by = $request->user()->id;
            $coupon->save();
            $this->syncProducts($coupon, $request->validated());
            $this->audit->log('coupon.created', $coupon, $request->validated());

            return $coupon;
        });

        return (new CouponResource($this->fresh($coupon)))->response()->setStatusCode(201);
    }

    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        DB::transaction(function () use ($request, $coupon) {
            $coupon->fill($this->attributes($request->validated()))->save();
            $this->syncProducts($coupon, $request->validated());
            $this->audit->log('coupon.updated', $coupon, $request->validated());
        });

        return new CouponResource($this->fresh($coupon));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        if (array_key_exists('affiliate_id', $data)) {
            $data['affiliate_profile_id'] = $data['affiliate_id'] ? AffiliateProfile::where('public_id', $data['affiliate_id'])->value('id') : null;
        }
        unset($data['affiliate_id'], $data['product_ids']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProducts(Coupon $coupon, array $data): void
    {
        if (array_key_exists('product_ids', $data)) {
            $coupon->products()->sync(Product::whereIn('public_id', $data['product_ids'])->pluck('id'));
        }
    }

    private function fresh(Coupon $coupon): Coupon
    {
        return Coupon::query()
            ->with(['affiliate', 'products'])
            ->withCount(['redemptions as active_redemptions_count' => fn ($q) => $q->whereNull('voided_at')])
            ->findOrFail($coupon->id);
    }
}
