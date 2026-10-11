<?php

namespace App\Models;

use App\Enums\PayoutMethodType;
use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * One request to pay an affiliate their available balance (ADR 0009).
 * Moved through its lifecycle only by PayoutService, each move paired with
 * a ledger entry. The amount and destination never change after creation.
 */
class Payout extends Model
{
    private const FROZEN = ['affiliate_profile_id', 'amount_minor', 'currency', 'method_type', 'account_name', 'destination', 'destination_masked', 'requested_at'];

    protected $guarded = ['*'];

    protected $hidden = ['id', 'affiliate_profile_id', 'open_affiliate_profile_id', 'destination', 'reviewed_by', 'paid_by'];

    protected static function booted(): void
    {
        static::creating(function (Payout $payout) {
            $payout->public_id ??= (string) Str::ulid();
        });
        static::updating(function (Payout $payout) {
            if ($payout->isDirty(self::FROZEN)) {
                throw new LogicException('A payout amount and destination cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Payouts are never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'method_type' => PayoutMethodType::class,
            'amount_minor' => 'integer',
            'destination' => 'encrypted:array',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedgerEntry::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by')->withTrashed();
    }
}
