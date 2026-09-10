<?php

namespace App\Jobs;

use App\Jobs\Middleware\FailOnTokenCeiling;
use App\Models\GenerationTask;
use App\Services\Generation\GenerationPipeline;
use App\Services\Generation\PromptRenderer;
use App\Services\Llm\LlmManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Step 2: run the Quality Assurance prompt against the SAME provider that
 * generated the product (FR-26, Assumptions §21), then surface the task on the
 * Task Board.
 */
class RunQaPromptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public GenerationTask $task,
    ) {
        $this->onQueue($task->topic->component->queue_name);
    }

    /**
     * The QA prompt re-sends the whole document, so a retry after running out
     * of tokens is the most expensive wasted call in the pipeline.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new FailOnTokenCeiling];
    }

    public function handle(GenerationPipeline $pipeline, LlmManager $llm, PromptRenderer $renderer): void
    {
        $this->task->refresh();

        // Defaults to the client's SGA-QCP-v2 vetting protocol, seeded onto
        // every component; a hand-written topic may still override it.
        $qaPrompt = $renderer->renderQaPrompt($this->task->topic)
            ."\n\n---\n\n"
            .$this->task->product->body;

        $result = $llm->for($this->task->llmProvider)->generate($qaPrompt);

        $pipeline->storeQaResult($this->task, $result);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationPipeline::class)->fail(
            $this->task->refresh(),
            $exception?->getMessage() ?? 'Unknown QA failure.',
        );
    }
}
