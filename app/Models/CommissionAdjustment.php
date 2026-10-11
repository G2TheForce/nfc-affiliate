<?php

namespace App\Models;

use App\Enums\AdjustmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * A compensating correction. Proposed by one staff member, approved or
 * rejected by another; only an approved adjustment reaches the ledger.
 */
class CommissionAdjustment extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'affiliate_profile_id', 'commission_id', 'created_by', 'decided_by'];

    protected static function booted(): void
    {
        static::creating(function (CommissionAdjustment $adjustment) {
            $adjustment->public_id ??= (string) Str::ulid();
        });
        static::updating(function (CommissionAdjustment $adjustment) {
            if ($adjustment->isDirty(['affiliate_profile_id', 'commission_id', 'amount_minor', 'currency', 'reason', 'created_by'])) {
                throw new LogicException('An adjustment cannot be edited; reject it and propose a new one.');
            }
        });
        static::deleting(fn () => throw new LogicException('Adjustments are never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => AdjustmentStatus::class,
            'amount_minor' => 'integer',
            'decided_at' => 'datetime',
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

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
