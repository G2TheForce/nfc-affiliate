<?php

namespace App\Enums;

/**
 * Commission lifecycle (ADR 0008). Holds and partial reversals are flags on
 * the commission (held_at, reversed_minor), not separate states, so a held or
 * partly refunded commission keeps its place in the lifecycle.
 */
enum CommissionStatus: string
{
    // Order paid; inside the holding period.
    case Pending = 'pending';
    // Holding period over with no refund or hold.
    case Approved = 'approved';
    // Released to the affiliate's available balance.
    case Available = 'available';
    // Included in a completed payout (Phase 5).
    case Paid = 'paid';
    // Fully reversed by refunds or voided by staff.
    case Reversed = 'reversed';

    public function bucket(): LedgerBucket
    {
        return match ($this) {
            self::Pending => LedgerBucket::Pending,
            self::Approved => LedgerBucket::Approved,
            self::Available => LedgerBucket::Available,
            self::Paid => LedgerBucket::Paid,
            self::Reversed => LedgerBucket::Reversed,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Available => 'Available',
            self::Paid => 'Paid',
            self::Reversed => 'Reversed',
        };
    }
}
