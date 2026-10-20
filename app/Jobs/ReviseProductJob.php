<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Jobs\Middleware\FailOnTokenCeiling;
use App\Models\GenerationTask;
use App\Models\LlmCallLog;
use App\Services\Generation\GenerationPipeline;
use App\Services\Generation\RevisionPromptBuilder;
use App\Services\Llm\LlmManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Step 3, and only when it is needed: the document failed its QA audit, so it
 * goes back to the same model with the audit report attached, and the rewrite
 * is then audited again.
 *
 * This is what stops a RED LIGHT document reaching the vault. Before it
 * existed the audit was advisory — the report was stored and the document went
 * to a proofreader regardless of what it said.
 *
 * Same provider as the original (FR-26, Assumptions §21): a different model
 * would be writing against an audit of someone else's prose.
 */
class ReviseProductJob implements ShouldQueue
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
     * A rewrite re-sends the specification, the whole previous draft and the
     * whole audit. Retrying it after the model ran out of tokens is the single
     * most expensive wasted call in the pipeline.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new FailOnTokenCeiling];
    }

    public function handle(GenerationPipeline $pipeline, LlmManager $llm, RevisionPromptBuilder $prompts): void
    {
        $this->task->refresh();

        // A retry that lands after the rewrite was already stored would start
        // a second one and burn another full generation.
        if ($this->task->status !== TaskStatus::Revising) {
            return;
        }

        $review = $this->task->latestQaReview();

        if ($review === null) {
            // Nothing to revise against. Treat it as a QA failure rather than
            // asking the model to guess what was wrong with the document.
            $pipeline->fail($this->task, 'Revision requested with no QA review to revise against.');

            return;
        }

        $result = $llm->for($this->task->llmProvider, LlmCallLog::CONTEXT_REVISION)
            ->generate($prompts->build($this->task, $review));

        $pipeline->storeRevision($this->task, $result);

        RunQaPromptJob::dispatch($this->task);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationPipeline::class)->fail(
            $this->task->refresh(),
            $exception?->getMessage() ?? 'Unknown revision failure.',
        );
    }
}
