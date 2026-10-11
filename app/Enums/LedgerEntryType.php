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

    public function label(): string
    {
        return match ($this) {
            self::Accrual => 'Commission earned',
            self::Approval => 'Holding period passed',
            self::Release => 'Added to available balance',
            self::RefundReversal => 'Reversed after a refund',
            self::Void => 'Voided by staff',
            self::Adjustment => 'Manual adjustment',
        };
    }
}
