<?php

namespace App\Enums;

/**
 * Delivery progress, kept apart from the payment status: a cash-on-delivery
 * order ships before it is paid, and a paid order can still be unshipped.
 */
enum FulfilmentStatus: string
{
    case Unfulfilled = 'unfulfilled';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Unfulfilled => 'Not yet processed',
            self::Processing => 'Being prepared',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Unfulfilled => 0,
            self::Processing => 1,
            self::Shipped => 2,
            self::Delivered => 3,
        };
    }
}
