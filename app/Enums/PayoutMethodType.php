<?php

namespace App\Enums;

/**
 * Ways finance can pay an affiliate by hand. No payout provider is
 * integrated; finance makes the transfer and records its reference.
 */
enum PayoutMethodType: string
{
    case BankTransfer = 'bank_transfer';
    case Esewa = 'esewa';
    case Khalti = 'khalti';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Esewa => 'eSewa',
            self::Khalti => 'Khalti',
        };
    }

    public function isWallet(): bool
    {
        return $this !== self::BankTransfer;
    }
}
