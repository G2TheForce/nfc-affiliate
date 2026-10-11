<?php

namespace App\Enums;

enum LedgerBucket: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Available = 'available';
    // Held for an open payout request.
    case Reserved = 'reserved';
    case Paid = 'paid';
    case Reversed = 'reversed';
}
