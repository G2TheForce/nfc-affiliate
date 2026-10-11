<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'SAVE'.Str::upper(Str::random(6)),
            'discount_type' => DiscountType::Percent,
            'discount_value' => 1000, // 10%
            'min_order_minor' => 0,
            'is_active' => true,
            'earns_commission' => true,
        ];
    }
}
