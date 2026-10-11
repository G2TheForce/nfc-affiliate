<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Evidence that money was received. Immutable: a wrong record is corrected
 * by a refund, never by editing the payment.
 */
class OrderPayment extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'order_id', 'confirmed_by'];

    protected static function booted(): void
    {
        static::creating(function (OrderPayment $payment) {
            $payment->public_id ??= (string) Str::ulid();
        });
        static::updating(fn () => throw new LogicException('Payments are immutable.'));
        static::deleting(fn () => throw new LogicException('Payments are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount_minor' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by')->withTrashed();
    }
}
