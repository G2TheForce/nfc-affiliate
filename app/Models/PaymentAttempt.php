<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One try at paying an order through eSewa or Khalti. Changed only by
 * ProviderPaymentService.
 */
class PaymentAttempt extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'order_id', 'reviewed_by'];

    protected static function booted(): void
    {
        static::creating(function (PaymentAttempt $attempt) {
            $attempt->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'amount_minor' => 'integer',
            'check_count' => 'integer',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
