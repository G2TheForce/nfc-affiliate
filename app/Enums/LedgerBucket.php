<?php

namespace App\Enums;

enum LedgerBucket: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Available = 'available';
    // Held for a payout request (Phase 5).
    case Reserved = 'reserved';
    case Paid = 'paid';
    case Reversed = 'reversed';
}
