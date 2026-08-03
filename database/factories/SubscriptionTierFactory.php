<?php

namespace Database\Factories;

use App\Models\SubscriptionTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionTier>
 */
class SubscriptionTierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'price' => fake()->randomFloat(2, 10, 500),
            'currency' => 'USD',
            'billing_period' => 'monthly',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => 0,
        ]);
    }
}
