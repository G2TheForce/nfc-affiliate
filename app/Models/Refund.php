<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class Refund extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'order_id', 'recorded_by'];

    protected static function booted(): void
    {
        static::creating(function (Refund $refund) {
            $refund->public_id ??= (string) Str::ulid();
        });
        static::updating(fn () => throw new LogicException('Refunds are immutable.'));
        static::deleting(fn () => throw new LogicException('Refunds are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'shipping_minor' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
