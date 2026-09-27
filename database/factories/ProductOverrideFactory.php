<?php

namespace Database\Factories;

use App\Models\ProductOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductOverride>
 */
class ProductOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'erp_product_id' => fake()->unique()->numberBetween(100000, 999999),
            'ref' => fake()->bothify('PART-####'),
            'is_hidden' => false,
            'profit_percentage' => null,
        ];
    }
}
