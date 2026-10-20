<?php

namespace App\Jobs;

use App\Enums\QaOutcome;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\FailOnTokenCeiling;
use App\Models\GenerationTask;
use App\Models\LlmCallLog;
use App\Services\Generation\GenerationPipeline;
use App\Services\Generation\PromptRenderer;
use App\Services\Llm\LlmManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Step 2: run the Quality Assurance prompt against the SAME provider that
 * generated the product (FR-26, Assumptions §21).
 *
 * What happens next depends on the verdict in the audit: a pass surfaces the
 * task on the Task Board, a failure sends the document back to the model to be
 * rewritten (ReviseProductJob), and a failure with no revision rounds left
 * parks the task in `qa_failed` where the vault cannot see it.
 */
class RunQaPromptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Longer than the HTTP call it waits on, or the worker kills itself
     * mid-generation: Laravel SIGKILLs a job that outruns its timeout, which
     * surfaces as exit 137 and spends the model credit for nothing.
     */
    public int $timeout;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public GenerationTask $task,
    ) {
        $this->onQueue($task->topic->component->queue_name);

        // Two minutes beyond the HTTP timeout it is waiting on.
        $this->timeout = (int) config('llm.timeout') + 120;
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

        // A retry that lands after the audit was already stored and acted on
        // would re-audit the document — a second full-document call, and a
        // second review row — and then fail on an illegal transition anyway.
        if ($this->task->status !== TaskStatus::QaRunning) {
            return;
        }

        // Defaults to the client's SGA-QCP-v2 vetting protocol, seeded onto
        // every component; a hand-written topic may still override it.
        $qaPrompt = $renderer->renderQaPrompt($this->task->topic)
            ."\n\n---\n\n"
            .$this->task->product->body;

        $result = $llm->for($this->task->llmProvider, LlmCallLog::CONTEXT_QA)->generate($qaPrompt);

        $outcome = $pipeline->storeQaResult($this->task, $result);

        // A RED LIGHT with a revision round left goes back to the model with
        // the audit attached. Dispatched here rather than inside the pipeline
        // so the task row is committed before a worker can pick it up.
        if ($outcome === QaOutcome::Revising) {
            ReviseProductJob::dispatch($this->task);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationPipeline::class)->fail(
            $this->task->refresh(),
            $exception?->getMessage() ?? 'Unknown QA failure.',
        );
    }
}
