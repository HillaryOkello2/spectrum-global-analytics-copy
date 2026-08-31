<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Component;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $paragraphs = fake()->paragraphs(4);

        return [
            'component_id' => Component::factory(),
            'topic_id' => null,
            'code' => 'SGA.TEST.'.fake()->unique()->numberBetween(1, 999999).'.'.fake()->date('m.y'),
            'title' => fake()->sentence(8),
            'byline' => fake()->sentence(10),
            'abstract' => fake()->paragraph(),
            'body' => implode("\n\n", $paragraphs),
            'redacted_body' => null,
            'redaction_approved' => false,
            'status' => ProductStatus::Draft,
            'is_hidden' => false,
            'approved_at' => null,
            'published_at' => null,
        ];
    }

    /**
     * Fresh out of the LLM: a body but no abstract yet — the abstract is
     * written by a proofreader, never generated.
     */
    public function withoutAbstract(): static
    {
        return $this->state(fn (array $attributes) => [
            'abstract' => null,
        ]);
    }

    public function redacted(): static
    {
        return $this->state(fn (array $attributes) => [
            'redacted_body' => implode("\n\n", fake()->paragraphs(2)),
            'redaction_approved' => true,
        ]);
    }

    /** Approved and sitting in the FIFO release queue, not yet live. */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Published,
            'approved_at' => now(),
            'published_at' => now(),
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_hidden' => true,
        ]);
    }
}
