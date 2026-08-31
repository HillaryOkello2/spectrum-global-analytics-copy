<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductRead>
 */
class ProductReadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id' => User::factory(),
            'read_at' => now(),
        ];
    }
}
