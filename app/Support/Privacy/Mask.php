<?php

namespace App\Support\Privacy;

/**
 * Masks contact details for staff screens that need to recognise a customer
 * without seeing the full value (support, attribution review).
 */
final class Mask
{
    public static function email(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email ? '***' : null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, min(2, mb_strlen($local))).'***@'.$domain;
    }

    public static function phone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        return str_repeat('*', max(0, strlen($phone) - 3)).substr($phone, -3);
    }

    /** Order reference shown to affiliates: enough to discuss, not to look up. */
    public static function orderNumber(string $orderNumber): string
    {
        return '…'.substr($orderNumber, -4);
    }
}
