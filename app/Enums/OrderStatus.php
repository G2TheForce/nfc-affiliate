<?php

namespace App\Enums;

/**
 * Paid and refunded are set only from verified payment or refund records
 * (Phase 4 and 7), never from a browser redirect.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }
}
