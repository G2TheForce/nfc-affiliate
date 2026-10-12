<?php

namespace App\Models;

use App\Enums\AttributionStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Orders are created only by CheckoutService. Totals are computed on the
 * server from catalog prices; nothing the browser sends is trusted.
 */
class Order extends Model
{
    protected $guarded = ['*'];

    protected $attributes = ['fulfilment_status' => 'unfulfilled'];

    protected $hidden = ['id', 'customer_user_id', 'coupon_id', 'idempotency_key', 'request_hash', 'access_token_hash'];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'fulfilment_status' => FulfilmentStatus::class,
            'attribution_status' => AttributionStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'shipping_minor' => 'integer',
            'total_minor' => 'integer',
            'refunded_minor' => 'integer',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'processing_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id')->withTrashed();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function attribution(): HasOne
    {
        return $this->hasOne(OrderAttribution::class);
    }

    public function couponRedemption(): HasOne
    {
        return $this->hasOne(CouponRedemption::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(OrderDispute::class);
    }

    /** True when the secret handed to the customer's browser at checkout matches. */
    public function accessTokenMatches(?string $token): bool
    {
        return $token !== null && $token !== '' && $this->access_token_hash !== null
            && hash_equals($this->access_token_hash, hash('sha256', $token));
    }

    public function payment(): HasOne
    {
        return $this->hasOne(OrderPayment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }
}
