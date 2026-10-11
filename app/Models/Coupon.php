<?php

namespace App\Models;

use App\Enums\DiscountType;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'affiliate_profile_id', 'discount_type', 'discount_value', 'min_order_minor', 'max_discount_minor',
        'usage_limit', 'per_customer_limit', 'starts_at', 'ends_at', 'is_active', 'earns_commission',
    ];

    protected $hidden = ['id', 'affiliate_profile_id', 'created_by'];

    protected static function booted(): void
    {
        static::creating(function (Coupon $coupon) {
            $coupon->public_id ??= (string) Str::ulid();
        });
        static::saving(function (Coupon $coupon) {
            $coupon->code = Str::upper(trim($coupon->code));
        });
    }

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'integer',
            'min_order_minor' => 'integer',
            'max_discount_minor' => 'integer',
            'usage_limit' => 'integer',
            'per_customer_limit' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'earns_commission' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
