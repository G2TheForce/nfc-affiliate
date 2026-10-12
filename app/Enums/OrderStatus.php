<?php

namespace App\Enums;

/**
 * Paid and refunded are set only from verified payment or refund records
 * (staff confirmation or a provider status check), never from a browser redirect.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
            self::PartiallyRefunded => 'Partly refunded',
            self::Refunded => 'Refunded',
        };
    }

    /** Statuses in which money was received and can be refunded. */
    public function isPaid(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }
}
