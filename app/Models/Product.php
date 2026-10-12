<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = ['category_id', 'sku', 'name', 'slug', 'description', 'price_minor', 'currency', 'is_active'];

    protected $hidden = ['id', 'category_id', 'image_path'];

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            $product->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Public address of the product photo, or null. */
    public function imageUrl(): ?string
    {
        return $this->image_path ? url('/media/'.$this->image_path) : null;
    }
}
