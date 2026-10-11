<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case Accrual = 'accrual';
    case Approval = 'approval';
    case Release = 'release';
    case RefundReversal = 'refund_reversal';
    case Void = 'void';
    case Adjustment = 'adjustment';
    case PayoutReserve = 'payout_reserve';
    case PayoutRelease = 'payout_release';
    case PayoutPaid = 'payout_paid';

    public function label(): string
    {
        return match ($this) {
            self::Accrual => 'Commission earned',
            self::Approval => 'Holding period passed',
            self::Release => 'Added to available balance',
            self::RefundReversal => 'Reversed after a refund',
            self::Void => 'Voided by staff',
            self::Adjustment => 'Manual adjustment',
            self::PayoutReserve => 'Reserved for a payout request',
            self::PayoutRelease => 'Returned from a payout that did not go ahead',
            self::PayoutPaid => 'Paid out',
        };
    }
}
