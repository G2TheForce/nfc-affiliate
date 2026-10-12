<?php

namespace App\Enums;

/**
 * How the customer paid. The payment's provider says how it was verified:
 * "manual" (staff checked it) or the online provider's status API.
 */
enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case CashOnDelivery = 'cash_on_delivery';
    case Esewa = 'esewa';
    case Khalti = 'khalti';
    case Fonepay = 'fonepay';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::CashOnDelivery => 'Cash on delivery',
            self::Esewa => 'eSewa',
            self::Khalti => 'Khalti',
            self::Fonepay => 'Fonepay QR',
            self::Other => 'Other',
        };
    }
}
