<?php

namespace App\Jobs;

use App\Models\GenerationTask;
use App\Services\Generation\GenerationPipeline;
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
        $this->onQueue('llm');
    }

    public function handle(GenerationPipeline $pipeline, LlmManager $llm): void
    {
        $this->task->refresh();

        $qaPrompt = $this->task->topic->qa_prompt_text
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
