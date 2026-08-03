<?php

namespace Database\Factories;

use App\Models\LlmProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmProvider>
 */
class LlmProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'vendor' => fake()->company(),
            'driver' => 'fake',
            'model_id' => fake()->slug(3),
            'is_active' => true,
        ];
    }
}
