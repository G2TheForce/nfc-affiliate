<?php

namespace App\Enums;

/**
 * How the customer paid, as recorded by staff confirming a manual payment.
 * Wallet payments recorded here were checked in the merchant dashboard by
 * staff; automatic provider verification arrives in Phase 7.
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
            self::Esewa => 'eSewa (checked manually)',
            self::Khalti => 'Khalti (checked manually)',
            self::Fonepay => 'Fonepay QR (checked manually)',
            self::Other => 'Other',
        };
    }
}
