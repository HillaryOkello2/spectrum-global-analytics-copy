<?php

namespace Database\Factories;

use App\Enums\Frequency;
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
        $code = 'T'.fake()->unique()->numberBetween(1, 9999);

        return [
            'name' => fake()->unique()->bs(),
            'code' => $code,
            'ref_code' => $code,
            'assigned_llm_provider_id' => LlmProvider::factory(),
            'batch' => fake()->numberBetween(1, 4),
            'is_transactional' => false,
            'sort_order' => 0,
            'prompt_template' => 'Write an analysis of [PRIMARY_TOPIC]. Reference: [DOCUMENT_REF], dated [DATE].',
            'topic_prompt' => null,
            'qa_prompt_template' => 'Audit the draft below against house standards.',
            'variables' => ['PRIMARY_TOPIC'],
            'fixed_variables' => [],
            'title_template' => null,
            'generation_frequency' => null,
            'queue_name' => 'llm',
        ];
    }

    /**
     * A recurring component that commissions its own topics — the daily/weekly/
     * monthly pulse shape.
     */
    public function selfCommissioning(Frequency $frequency = Frequency::Daily): static
    {
        return $this->state(fn (array $attributes) => [
            'generation_frequency' => $frequency,
            'topic_prompt' => 'Pick this edition\'s subject. Return ONLY a JSON object with these keys:'
                ."\n".'{ "PRIMARY_TOPIC": "<value>", "BYLINE": "<value>" }',
            'variables' => ['PRIMARY_TOPIC', 'BYLINE'],
            'fixed_variables' => ['DOCUMENT_TITLE' => 'TEST PULSE BRIEF'],
            'prompt_template' => 'Title: [DOCUMENT_TITLE]. Byline: [BYLINE]. Subject: [PRIMARY_TOPIC]. '
                .'Reference: [DOCUMENT_REF], dated [DATE].',
            'title_template' => '[DOCUMENT_TITLE] — [DATE]',
        ]);
    }

    public function transactional(): static
    {
        return $this->state(fn (array $attributes) => [
            'batch' => 5,
            'is_transactional' => true,
        ]);
    }
}
