<?php

namespace App\Enums;

enum DiscountType: string
{
    // discount_value in basis points (1000 = 10%).
    case Percent = 'percent';
    // discount_value in minor units.
    case Fixed = 'fixed';
}
