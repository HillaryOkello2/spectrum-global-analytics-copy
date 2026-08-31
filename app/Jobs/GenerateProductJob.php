<?php

namespace App\Jobs;

use App\Models\GenerationTask;
use App\Services\Generation\GenerationPipeline;
use App\Services\Generation\PromptRenderer;
use App\Services\Llm\LlmManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Step 1 of the pipeline: send the Topic's prompt to the component's assigned LLM
 * (FR-24/25), then chain the QA prompt job. Retries with backoff; a final
 * failure leaves the task in `failed` with the error surfaced to Admins (R-01).
 */
class GenerateProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public GenerationTask $task,
    ) {
        // Per-component queue: a 56-page Research Paper must not head-of-line
        // block the daily brief.
        $this->onQueue($task->topic->component->queue_name);
    }

    public function handle(GenerationPipeline $pipeline, LlmManager $llm, PromptRenderer $renderer): void
    {
        $this->task->refresh();

        // Reserves the product code first — the document prints it as its
        // [DOCUMENT_REF], so the prompt has to be rendered against it.
        $product = $pipeline->beginGeneration($this->task);

        $prompt = $renderer->renderProductPrompt($this->task->topic, $product->code);

        $result = $llm->for($this->task->llmProvider)->generate($prompt);

        $pipeline->storeGeneratedProduct($this->task, $result);

        RunQaPromptJob::dispatch($this->task);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationPipeline::class)->fail(
            $this->task->refresh(),
            $exception?->getMessage() ?? 'Unknown generation failure.',
        );
    }
}
