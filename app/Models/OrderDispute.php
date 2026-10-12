<?php

namespace App\Models;

use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A payment dispute or chargeback raised by the customer's bank or wallet.
 * While open, the order's commissions are held; a lost dispute is recorded
 * as a refund so the commission is reversed through the normal path.
 */
class OrderDispute extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'order_id', 'open_order_id', 'opened_by', 'resolved_by', 'refund_id', 'held_commission_ids'];

    protected static function booted(): void
    {
        static::creating(function (OrderDispute $dispute) {
            $dispute->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'amount_minor' => 'integer',
            'held_commission_ids' => 'array',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
