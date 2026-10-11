<?php

namespace App\Enums;

/**
 * Payout lifecycle (ADR 0009). Requested and approved payouts hold the
 * affiliate's funds in the reserved bucket; every other state is final.
 */
enum PayoutStatus: string
{
    // Affiliate asked to be paid; funds reserved; waiting for finance.
    case Requested = 'requested';
    // Finance checked it; waiting for the transfer to be made and confirmed.
    case Approved = 'approved';
    // Transfer confirmed with a reference. Final.
    case Paid = 'paid';
    // Finance declined it; funds back in the available balance. Final.
    case Rejected = 'rejected';
    // The transfer did not go through; funds back in the available balance. Final.
    case Failed = 'failed';
    // The affiliate withdrew it before review; funds back. Final.
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Requested || $this === self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
