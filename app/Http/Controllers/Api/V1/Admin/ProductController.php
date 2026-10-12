<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Store\ProductImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Resources\Store\ProductResource;
use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    public function storeImage(Request $request, Product $product, ProductImages $images): ProductResource
    {
        $request->validate(['image' => ['required', 'file', 'max:5120']], ['image.max' => 'The photo must be 5 MB or smaller.']);
        $images->replace($product, $request->file('image'), $request->user());

        return new ProductResource($product->load('category'));
    }

    public function destroyImage(Request $request, Product $product, ProductImages $images): ProductResource
    {
        $images->remove($product, $request->user());

        return new ProductResource($product->load('category'));
    }

    public function categories(): array
    {
        return ['data' => ProductCategory::query()->withCount('products')->orderBy('name')->get()
            ->map(fn (ProductCategory $c) => $c->only(['id', 'name', 'slug', 'is_active']) + ['products_count' => $c->products_count])];
    }

    /** The web address (slug) stays fixed, because shared store links may use it. */
    public function updateCategory(Request $request, ProductCategory $category): array
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'slug' => ['prohibited'],
        ], ['slug.prohibited' => 'A category\'s web address cannot change.']);

        $category->fill($data);
        $changes = $category->getDirty();
        if ($changes !== []) {
            DB::transaction(function () use ($category, $changes) {
                $category->save();
                $this->audit->log('product_category.updated', $category, $changes);
            });
        }

        return ['data' => $category->only(['id', 'name', 'slug', 'is_active']) + ['products_count' => $category->products()->count()]];
    }

    /** Only a category with no products, past orders or commission rules can be deleted. */
    public function destroyCategory(ProductCategory $category): Response
    {
        if ($category->products()->exists()) {
            throw ValidationException::withMessages(['category' => 'Move this category\'s products to another category first.']);
        }
        if (DB::table('order_items')->where('category_id', $category->id)->exists()) {
            throw ValidationException::withMessages(['category' => 'Past orders use this category, so it stays for reports. Hide it instead.']);
        }
        if (CommissionRule::query()->where('product_category_id', $category->id)->exists()) {
            throw ValidationException::withMessages(['category' => 'A commission rule uses this category. Hide the category instead.']);
        }

        DB::transaction(function () use ($category) {
            $category->delete();
            $this->audit->log('product_category.deleted', $category, $category->only(['name', 'slug']));
        });

        return response()->noContent();
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
