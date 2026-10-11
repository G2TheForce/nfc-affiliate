<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A commission covered by a payout, with its net amount at request time.
 */
class PayoutItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'payout_id', 'commission_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payout items are immutable.'));
        static::deleting(fn () => throw new LogicException('Payout items are immutable.'));
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }
}
