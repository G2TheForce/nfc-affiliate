<?php

namespace App\Models;

use App\Enums\AffiliateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only record of every membership status change.
 */
class AffiliateStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['from_status', 'to_status', 'actor_id', 'public_reason', 'internal_note'];

    protected function casts(): array
    {
        return [
            'from_status' => AffiliateStatus::class,
            'to_status' => AffiliateStatus::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Status history is immutable.'));
        static::deleting(fn () => throw new LogicException('Status history is immutable.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
