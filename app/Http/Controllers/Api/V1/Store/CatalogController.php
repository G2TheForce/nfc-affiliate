<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Store\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController extends Controller
{
    public function products(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string', 'max:140'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $products = Product::active()
            ->with('category')
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.addcslashes($s, '%_\\').'%'))
            ->orderBy('name')
            ->paginate(48)
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function product(string $slug): ProductResource
    {
        return new ProductResource(Product::active()->with('category')->where('slug', $slug)->firstOrFail());
    }

    public function categories(): array
    {
        return ['data' => ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug'])];
    }
}
