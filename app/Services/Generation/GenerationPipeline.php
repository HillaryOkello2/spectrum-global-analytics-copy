<?php

namespace App\Services\Generation;

use App\Enums\ProductStatus;
use App\Enums\TaskStatus;
use App\Exceptions\Domain\InvalidTaskTransitionException;
use App\Jobs\GenerateProductJob;
use App\Models\GenerationTask;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use App\Services\Catalog\ProductCodeService;
use App\Services\Llm\DTOs\LlmResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * State machine for Topic → LLM → QA → Task Board → Proofreading → Redaction →
 * Approval → Release (FR-24–29, §16.1, §18.4). All transitions are guarded;
 * invalid ones throw.
 *
 * Approval is where editorial work ends, not where content goes live: approved
 * products join the FIFO release queue and are published by `products:release`
 * (see ReleaseService).
 */
class GenerationPipeline
{
    public function __construct(
        private readonly ProductCodeService $codes,
        private readonly PromptRenderer $renderer,
        private readonly AbstractExtractor $abstracts,
    ) {}

    /**
     * Create a queued generation task for a Topic and dispatch the job (FR-46).
     */
    public function queueTopic(Topic $topic): GenerationTask
    {
        $task = GenerationTask::create([
            'topic_id' => $topic->id,
            'llm_provider_id' => $topic->component->assigned_llm_provider_id,
            'status' => TaskStatus::Queued,
            'queued_at' => now(),
        ]);

        $topic->update(['last_generated_at' => now()]);

        GenerateProductJob::dispatch($task);

        return $task;
    }

    /**
     * Create the empty Product the LLM is about to fill, and move the task to
     * `generating`.
     *
     * The shell exists *before* the model is called because the client's prompts
     * print the product's code as the document's [DOCUMENT_REF] — so the code
     * has to be allocated and reserved first, and the prompt is rendered against
     * it. Allocating without inserting would drop the sequence lock and let two
     * concurrent jobs claim the same code.
     */
    public function beginGeneration(GenerationTask $task): Product
    {
        return DB::transaction(function () use ($task) {
            $product = $task->product ?? $this->createProductShell($task);

            // The caller holds this same task instance, and its `product`
            // relation was resolved as null before the shell existed. Seed it so
            // storeGeneratedProduct sees the product without a refetch.
            $task->setRelation('product', $product);

            // A retry re-enters here with the task already `generating`, which is
            // not a legal transition; count the attempt and carry on rather than
            // failing the job on its own retry.
            if ($task->status === TaskStatus::Generating) {
                $task->update(['product_id' => $product->id, 'attempts' => $task->attempts + 1]);

                return $product;
            }

            $this->transition($task, TaskStatus::Generating, [
                'product_id' => $product->id,
                'started_at' => now(),
                'attempts' => $task->attempts + 1,
            ]);

            return $product;
        });
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function subtitle(array $variables): ?string
    {
        $subtitle = $variables['BYLINE']
            ?? $variables['DOCUMENT_SUBTITLE']
            ?? $variables['TARGET_THREAT_MATRIX']
            ?? null;

        return $subtitle === null ? null : str($subtitle)->limit(250)->value();
    }

    /**
     * Persist the LLM output onto the reserved Product and move to QA (FR-25/26).
     */
    public function storeGeneratedProduct(GenerationTask $task, LlmResult $result): Product
    {
        return DB::transaction(function () use ($task, $result) {
            $product = $task->product;

            $product->update(['body' => $result->text]);

            $this->transition($task, TaskStatus::QaRunning);

            return $product;
        });
    }

    private function createProductShell(GenerationTask $task): Product
    {
        $topic = $task->topic;
        $variables = $topic->variables ?? [];

        return Product::create([
            'component_id' => $topic->component_id,
            'topic_id' => $topic->id,
            // Allocated under the same transaction as the insert — that is
            // what makes the sequence lock meaningful.
            'code' => $this->codes->allocate($topic->component),
            'title' => str($this->renderer->renderTitle($topic))->limit(250)->value(),
            // Each prompt names its subtitle slot differently. Truncated like
            // the title: both columns are varchar(255), the value comes from a
            // model, and a long one must not take down the insert — GPT-4o
            // returned a 386-character TARGET_THREAT_MATRIX and failed the task.
            'byline' => $this->subtitle($variables),
            // Filled by storeGeneratedProduct once the model responds.
            'body' => '',
            // No abstract: it is written by a proofreader, never generated.
            'status' => ProductStatus::Draft,
        ]);
    }

    /**
     * Record the QA prompt output and surface the task on the Task Board (FR-26/27).
     */
    public function storeQaResult(GenerationTask $task, LlmResult $result): void
    {
        DB::transaction(function () use ($task, $result): void {
            $this->transition($task, TaskStatus::AwaitingProofreading, ['qa_result' => $result->text]);

            $task->product->update(['status' => ProductStatus::AwaitingProofreading]);
        });
    }

    /**
     * Proofreader opens the task — capture identity + timestamp (FR-45).
     */
    public function openProofreading(GenerationTask $task, User $proofreader): void
    {
        $this->transition($task, TaskStatus::InProofreading, [
            'proofreader_id' => $proofreader->id,
            'proofread_at' => now(),
        ]);

        $task->product->update(['status' => ProductStatus::InProofreading]);

        activity()->causedBy($proofreader)->performedOn($task)->log('proofreading opened');
    }

    /**
     * Proofreading stage 1: the corrected document. Hands over to redaction.
     *
     * The proofreader submits the body alone. The public abstract is lifted
     * from that body's own Executive Summary — PART I of every product is the
     * client's Abstract Paper, so the text already exists and asking a human to
     * write a second one by hand only invites the two to drift apart.
     *
     * It is derived here rather than at generation because it must reflect the
     * proofread document, not the draft the model produced.
     *
     * @param  array<string, string|null>  $attributes  body
     */
    public function submitProofread(GenerationTask $task, User $proofreader, array $attributes): void
    {
        DB::transaction(function () use ($task, $proofreader, $attributes): void {
            $this->transition($task, TaskStatus::AwaitingRedaction, [
                'proofreader_id' => $proofreader->id,
                'proofread_at' => now(),
            ]);

            $body = (string) Arr::get($attributes, 'body');

            $task->product->update([
                'body' => $body,
                // Null would leave the product approved but permanently unable
                // to release, since the FIFO queue skips products without one.
                // Keep whatever is already there rather than clear it.
                'abstract' => $this->abstracts->extract($body) ?? $task->product->abstract,
                'status' => ProductStatus::AwaitingRedaction,
            ]);
        });

        activity()->causedBy($proofreader)->performedOn($task)->log('document proofread');
    }

    /**
     * Proofreading stage 2: the redacted document, reviewed separately from the
     * full one. Completing it approves the product, which then joins the FIFO
     * release queue — approval no longer publishes (FR-28/29, §6).
     */
    public function submitRedaction(GenerationTask $task, User $redactor, string $redactedBody): void
    {
        DB::transaction(function () use ($task, $redactor, $redactedBody): void {
            $this->transition($task, TaskStatus::Approved, [
                'redactor_id' => $redactor->id,
                'redacted_at' => now(),
            ]);

            $task->product->update([
                'redacted_body' => $redactedBody,
                'redaction_approved' => true,
                'status' => ProductStatus::Approved,
                'approved_at' => now(),
            ]);
        });

        activity()->causedBy($redactor)->performedOn($task)->log('redaction approved');
    }

    /**
     * Approve without a redaction pass, for products that do not need one.
     * Queues the product for release; it does not go live here.
     */
    public function approve(GenerationTask $task, User $approver): void
    {
        DB::transaction(function () use ($task): void {
            $this->transition($task, TaskStatus::Approved);

            $task->product->update([
                'status' => ProductStatus::Approved,
                'approved_at' => now(),
            ]);
        });

        activity()->causedBy($approver)->performedOn($task)->log('product approved');
    }

    public function reject(GenerationTask $task, User $reviewer, string $note): void
    {
        DB::transaction(function () use ($task, $note): void {
            $this->transition($task, TaskStatus::Rejected, ['rejection_note' => $note]);

            $task->product?->update(['status' => ProductStatus::Rejected]);
        });

        activity()->causedBy($reviewer)->performedOn($task)->log('product rejected');
    }

    public function fail(GenerationTask $task, string $error): void
    {
        $task->update([
            'status' => TaskStatus::Failed,
            'last_error' => $error,
            'completed_at' => now(),
        ]);
    }

    private function transition(GenerationTask $task, TaskStatus $to, array $attributes = []): void
    {
        if (! $task->status->canTransitionTo($to)) {
            throw new InvalidTaskTransitionException($task->status, $to);
        }

        $task->update([...$attributes, 'status' => $to]);
    }
}
