<?php

namespace Database\Factories;

use App\Enums\Frequency;
use App\Models\Component;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Topic>
 */
class TopicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'component_id' => Component::factory(),
            'title' => fake()->sentence(6),
            'frequency' => fake()->randomElement(Frequency::cases()),
            'prompt_text' => fake()->paragraph(),
            'qa_prompt_text' => fake()->paragraph(),
            'is_active' => true,
            'last_generated_at' => null,
        ];
    }
}
