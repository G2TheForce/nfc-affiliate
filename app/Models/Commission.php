<?php

namespace App\Models;

use App\Enums\CommissionRuleKind;
use App\Enums\CommissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * One affiliate's commission on one order line. Created by CommissionService
 * and moved through its lifecycle only by the commission services, each move
 * paired with a ledger entry. The calculation fields never change after
 * creation; refunds add to reversed_minor instead.
 */
class Commission extends Model
{
    private const FROZEN = ['affiliate_profile_id', 'order_id', 'order_item_id', 'commission_rule_id', 'rule_version',
        'rule_scope', 'kind', 'base_minor', 'rate_bp', 'fixed_minor', 'quantity', 'amount_minor', 'currency', 'snapshot'];

    protected $guarded = ['*'];

    protected $hidden = ['id', 'affiliate_profile_id', 'order_id', 'order_item_id', 'commission_rule_id'];

    protected static function booted(): void
    {
        static::creating(function (Commission $commission) {
            $commission->public_id ??= (string) Str::ulid();
        });
        static::updating(function (Commission $commission) {
            if ($commission->isDirty(self::FROZEN)) {
                throw new LogicException('Commission calculations are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Commissions are never deleted; reverse them instead.'));
    }

    protected function casts(): array
    {
        return [
            'status' => CommissionStatus::class,
            'kind' => CommissionRuleKind::class,
            'base_minor' => 'integer',
            'rate_bp' => 'integer',
            'fixed_minor' => 'integer',
            'quantity' => 'integer',
            'amount_minor' => 'integer',
            'reversed_minor' => 'integer',
            'rule_version' => 'integer',
            'snapshot' => 'array',
            'eligible_at' => 'datetime',
            'held_at' => 'datetime',
            'approved_at' => 'datetime',
            'available_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** What the affiliate is still owed for this line after reversals. */
    public function netMinor(): int
    {
        return $this->amount_minor - $this->reversed_minor;
    }

    public function isHeld(): bool
    {
        return $this->held_at !== null;
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedgerEntry::class);
    }
}
