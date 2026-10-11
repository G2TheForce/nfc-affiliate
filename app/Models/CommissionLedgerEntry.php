<?php

namespace App\Models;

use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only journal (ADR 0003). Written only by CommissionLedger.
 */
class CommissionLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'affiliate_profile_id', 'commission_id', 'commission_adjustment_id', 'refund_id', 'payout_id', 'actor_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'from_bucket' => LedgerBucket::class,
            'to_bucket' => LedgerBucket::class,
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(CommissionAdjustment::class, 'commission_adjustment_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
