<?php

namespace Database\Factories;

use App\Models\Component;
use App\Models\LlmProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Component>
 */
class ComponentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bs(),
            'code' => 'A'.fake()->unique()->numberBetween(1, 9999),
            'assigned_llm_provider_id' => LlmProvider::factory(),
            'batch' => fake()->numberBetween(1, 4),
            'is_transactional' => false,
            'sort_order' => 0,
        ];
    }

    public function transactional(): static
    {
        return $this->state(fn (array $attributes) => [
            'batch' => 5,
            'is_transactional' => true,
        ]);
    }
}
