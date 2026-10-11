<?php

namespace App\Support\Privacy;

/**
 * HMAC-SHA256 with the application key: lets us match repeat values
 * (same visitor, same customer email) without storing the value itself.
 */
final class KeyedHash
{
    public static function make(string $purpose, string $value): string
    {
        return hash_hmac('sha256', $purpose.':'.$value, (string) config('app.key'));
    }
}
