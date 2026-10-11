<?php

namespace App\Support;

final class Money
{
    /** "NPR 1,234.50" from minor units; negative amounts keep their sign. */
    public static function format(int $minor, string $currency): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $currency.' '.$sign.number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
