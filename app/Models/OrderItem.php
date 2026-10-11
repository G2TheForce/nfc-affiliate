<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrderItem extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'order_id', 'product_id', 'category_id'];

    protected static function booted(): void
    {
        static::creating(function (OrderItem $item) {
            $item->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'quantity' => 'integer',
            'line_subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function refundItems(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }
}
