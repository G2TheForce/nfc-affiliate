<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Resources\Store\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return ProductResource::collection(
            Product::query()->with('category')
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                    ->where('name', 'like', '%'.addcslashes($s, '%_\\').'%')->orWhere('sku', $s)))
                ->orderBy('name')
                ->paginate(100)
        );
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = Product::create([...$request->validated(), 'currency' => config('affiliate.currency.default')]);
        $this->audit->log('product.created', $product, $request->validated());

        return (new ProductResource($product->load('category')))->response()->setStatusCode(201);
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $product->fill($request->validated());
        $changes = $product->getDirty();
        $product->save();
        $this->audit->log('product.updated', $product, $changes);

        return new ProductResource($product->load('category'));
    }

    public function categories(): array
    {
        return ['data' => ProductCategory::query()->orderBy('name')->get(['id', 'name', 'slug', 'is_active'])];
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:product_categories,slug'],
        ]);
        $category = ProductCategory::create($data);
        $this->audit->log('product_category.created', $category, $data);

        return response()->json(['data' => $category->only(['id', 'name', 'slug', 'is_active'])], 201);
    }
}
