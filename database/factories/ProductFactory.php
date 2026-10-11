<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = 'NFC Card '.fake()->unique()->words(2, true);

        return [
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->sentence(),
            'price_minor' => 150000, // NPR 1,500
            'currency' => 'NPR',
            'is_active' => true,
        ];
    }
}
