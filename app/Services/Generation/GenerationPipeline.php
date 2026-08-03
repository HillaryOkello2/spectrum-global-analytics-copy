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

    public function markGenerating(GenerationTask $task): void
    {
        $this->transition($task, TaskStatus::Generating, [
            'started_at' => now(),
            'attempts' => $task->attempts + 1,
        ]);
    }

    /**
     * Persist the LLM output as a draft Product and move to the QA stage (FR-25/26).
     */
    public function storeGeneratedProduct(GenerationTask $task, LlmResult $result): Product
    {
        return DB::transaction(function () use ($task, $result) {
            $topic = $task->topic;

            $product = Product::create([
                'component_id' => $topic->component_id,
                'topic_id' => $topic->id,
                // Allocated under the same transaction as the insert — that is
                // what makes the sequence lock meaningful.
                'code' => $this->codes->allocate($topic->component, $topic),
                'title' => $topic->title,
                'body' => $result->text,
                // No abstract: it is written by a proofreader, never generated.
                'status' => ProductStatus::Draft,
            ]);

            $this->transition($task, TaskStatus::QaRunning, ['product_id' => $product->id]);

            return $product;
        });
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
     * Proofreading stage 1: the corrected abstract and document. The abstract is
     * authored here — the LLM never writes one — and is what the public sees, so
     * this step is what makes a product releasable. Hands over to redaction.
     */
    public function submitProofread(GenerationTask $task, User $proofreader, string $abstract, string $body): void
    {
        DB::transaction(function () use ($task, $proofreader, $abstract, $body): void {
            $this->transition($task, TaskStatus::AwaitingRedaction, [
                'proofreader_id' => $proofreader->id,
                'proofread_at' => now(),
            ]);

            $task->product->update([
                'abstract' => $abstract,
                'body' => $body,
                'status' => ProductStatus::AwaitingRedaction,
            ]);
        });

        activity()->causedBy($proofreader)->performedOn($task)->log('abstract and document proofread');
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
