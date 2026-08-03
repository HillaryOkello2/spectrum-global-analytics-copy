<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\GenerationTask;
use App\Models\LlmProvider;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GenerationTask>
 */
class GenerationTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'topic_id' => Topic::factory(),
            'product_id' => null,
            'llm_provider_id' => LlmProvider::factory(),
            'status' => TaskStatus::Queued,
            'attempts' => 0,
            'queued_at' => now(),
        ];
    }

    public function awaitingProofreading(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::AwaitingProofreading,
        ]);
    }
}
