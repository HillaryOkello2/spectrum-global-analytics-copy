<?php

namespace Database\Factories;

use App\Models\LlmCallLog;
use App\Models\LlmProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmCallLog>
 */
class LlmCallLogFactory extends Factory
{
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-12 hours', 'now');

        return [
            'llm_provider_id' => LlmProvider::factory(),
            'started_at' => $startedAt,
            'finished_at' => $startedAt,
            'success' => true,
            'latency_ms' => fake()->numberBetween(400, 4000),
            'error_code' => null,
            'error_message' => null,
            'context' => LlmCallLog::CONTEXT_GENERATION,
            'is_probe' => false,
        ];
    }

    public function failed(string $code = '429', string $message = 'Quota exceeded for requests per minute'): static
    {
        return $this->state(fn () => [
            'success' => false,
            'latency_ms' => null,
            'error_code' => $code,
            'error_message' => $message,
        ]);
    }

    public function probe(): static
    {
        return $this->state(fn () => [
            'is_probe' => true,
            'context' => LlmCallLog::CONTEXT_PROBE,
        ]);
    }

    public function at(\DateTimeInterface $when): static
    {
        return $this->state(fn () => ['started_at' => $when, 'finished_at' => $when]);
    }
}
