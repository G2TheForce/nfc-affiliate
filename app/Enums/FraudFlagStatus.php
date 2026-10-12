<?php

namespace App\Enums;

enum FraudFlagStatus: string
{
    case Open = 'open';
    case Dismissed = 'dismissed';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Dismissed => 'Dismissed',
            self::Confirmed => 'Confirmed',
        };
    }
}
