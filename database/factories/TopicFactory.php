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
            'source' => Topic::SOURCE_MANUAL,
            'variables' => null,
            'prompt_text' => fake()->paragraph(),
            'qa_prompt_text' => fake()->paragraph(),
            'is_active' => true,
            'last_generated_at' => null,
        ];
    }

    /**
     * A topic the scheduler commissioned: no prompt of its own, just the
     * variables that get rendered into the component's template.
     *
     * @param  array<string, string>  $variables
     */
    public function auto(array $variables = ['PRIMARY_TOPIC' => 'A structural assessment of sovereign compute capacity']): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => Topic::SOURCE_AUTO,
            'variables' => $variables,
            'prompt_text' => null,
            'qa_prompt_text' => null,
        ]);
    }
}
