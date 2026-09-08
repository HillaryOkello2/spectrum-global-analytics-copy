<?php

use App\Models\Component;
use App\Models\GenerationTask;
use App\Models\LlmProvider;
use App\Models\Topic;
use App\Services\Generation\GenerationPipeline;

it('truncates an over-long subtitle instead of failing the insert', function (): void {
    // products.byline is varchar(255) and the value comes from a model. GPT-4o
    // returned a 386-character TARGET_THREAT_MATRIX, which killed the task on
    // the shell insert before generation had even started.
    $component = Component::factory()->create([
        'variables' => ['PROJECT_FILE', 'DOCUMENT_TITLE', 'TARGET_THREAT_MATRIX'],
        'fixed_variables' => [],
        'title_template' => '[PROJECT_FILE]: [DOCUMENT_TITLE]',
    ]);

    $topic = $component->topics()->create([
        'title' => 'Placeholder',
        'frequency' => 'monthly',
        'source' => Topic::SOURCE_AUTO,
        'variables' => [
            'PROJECT_FILE' => 'PROJECT TITANIC SUNRISE',
            'DOCUMENT_TITLE' => str_repeat('Long title. ', 40),
            'TARGET_THREAT_MATRIX' => str_repeat('A coupled systemic threat. ', 20),
        ],
        'is_active' => true,
    ]);

    $task = GenerationTask::create([
        'topic_id' => $topic->id,
        'llm_provider_id' => LlmProvider::factory()->create()->id,
        'status' => 'queued',
        'queued_at' => now(),
    ]);

    $product = app(GenerationPipeline::class)->beginGeneration($task);

    expect(strlen($product->byline))->toBeLessThanOrEqual(255)
        ->and(strlen($product->title))->toBeLessThanOrEqual(255);
});
