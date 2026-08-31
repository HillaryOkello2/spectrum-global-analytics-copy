<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductRating;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductRating>
 */
class ProductRatingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id' => User::factory(),
            'stars' => fake()->numberBetween(ProductRating::MIN_STARS, ProductRating::MAX_STARS),
        ];
    }
}
