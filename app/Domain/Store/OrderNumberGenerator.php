<?php

namespace App\Domain\Store;

use App\Models\Order;

class OrderNumberGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** e.g. NFC-261011-7KQ2MX: business date plus a random suffix. */
    public function next(): string
    {
        $prefix = (string) config('affiliate.store.order_number_prefix').'-'.now(config('affiliate.business_timezone'))->format('ymd').'-';

        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Order::where('order_number', $prefix.$suffix)->exists());

        return $prefix.$suffix;
    }
}
