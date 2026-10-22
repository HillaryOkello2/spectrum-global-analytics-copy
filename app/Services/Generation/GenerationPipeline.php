<?php

namespace App\Services\Generation;

use App\Enums\ProductStatus;
use App\Enums\QaOutcome;
use App\Enums\QaVerdict;
use App\Enums\TaskStatus;
use App\Exceptions\Domain\InvalidTaskTransitionException;
use App\Exceptions\Domain\ProductNotProofreadException;
use App\Jobs\GenerateProductJob;
use App\Models\GenerationTask;
use App\Models\Product;
use App\Models\QaReview;
use App\Models\Topic;
use App\Models\User;
use App\Services\Catalog\ProductCodeService;
use App\Services\Generation\DTOs\QaReport;
use App\Services\Llm\DTOs\LlmResult;
use App\Support\Audit;
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
        private readonly QaReportParser $qaReports,
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
     * Record the QA audit and act on its verdict (FR-26/27).
     *
     * The verdict used to be advisory: whatever the audit said, the document
     * went to the Task Board and a proofreader read the report. It is now a
     * gate. A RED LIGHT document is sent back to the same model with the audit
     * attached (ReviseProductJob), and if it is still failing when the
     * revision rounds run out it is parked in `qa_failed` — it never reaches
     * the vault, which is the point.
     *
     * Two things are worth knowing about this gate. LLM auditors are not
     * deterministic: the same document has come back GREEN on one run and RED
     * on the next with nothing about it changed, which is why there are
     * revision rounds rather than a single pass, and why
     * `tasks/{task}/qa-override` lets a human overrule a verdict instead of
     * losing the document. And `qa.enforce` turns the gate off entirely while
     * still parsing and storing every report.
     */
    public function storeQaResult(GenerationTask $task, LlmResult $result): QaOutcome
    {
        $report = $this->qaReports->parse($result->text);
        $previousVerdict = $task->qa_verdict;

        return DB::transaction(function () use ($task, $report, $previousVerdict): QaOutcome {
            $review = QaReview::create([
                'generation_task_id' => $task->id,
                'product_id' => $task->product_id,
                'llm_provider_id' => $task->llm_provider_id,
                'round' => $task->qa_round,
                'verdict' => $report->verdict,
                'report' => $report->raw,
                'failed_sections' => $report->failedSections,
                'rerun_instructions' => $report->rerunInstructions,
            ]);

            $outcome = $this->decide($task, $report);

            match ($outcome) {
                QaOutcome::Accepted => $this->acceptQa($task, $report),
                QaOutcome::Revising => $this->queueRevision($task, $report),
                QaOutcome::Failed => $this->failQa($task, $report),
            };

            // Surfaces on the task board and in the audit log as the verdict
            // plus the sections that failed, not as "QA ran".
            Audit::of($task)
                ->change('qa_verdict', $previousVerdict, $report->verdict, 'QA verdict')
                ->with([
                    'round' => $review->round,
                    'failedSections' => $report->failedSections,
                    'outcome' => $outcome->value,
                ])
                ->log('QA audit '.$report->verdict->label().' (round '.$review->round.')');

            return $outcome;
        });
    }

    /**
     * The verdict, the gate switch and the remaining rounds, in one place.
     */
    private function decide(GenerationTask $task, QaReport $report): QaOutcome
    {
        if (! config('qa.enforce')) {
            return QaOutcome::Accepted;
        }

        $failing = match ($report->verdict) {
            QaVerdict::Green => false,
            QaVerdict::Red => true,
            // An audit nobody can read is a fault in the audit, not evidence
            // about the document; what to do with it is a config decision.
            QaVerdict::Unknown => config('qa.unknown_verdict') !== 'pass',
        };

        if (! $failing) {
            return QaOutcome::Accepted;
        }

        if ($report->verdict === QaVerdict::Unknown && config('qa.unknown_verdict') === 'fail') {
            return QaOutcome::Failed;
        }

        return $task->revisionsRemaining() > 0 ? QaOutcome::Revising : QaOutcome::Failed;
    }

    private function acceptQa(GenerationTask $task, QaReport $report): void
    {
        $this->transition($task, TaskStatus::AwaitingProofreading, [
            'qa_result' => $report->raw,
            'qa_verdict' => $report->verdict,
        ]);

        $task->product->update(['status' => ProductStatus::AwaitingProofreading]);
    }

    private function queueRevision(GenerationTask $task, QaReport $report): void
    {
        $this->transition($task, TaskStatus::Revising, [
            'qa_result' => $report->raw,
            'qa_verdict' => $report->verdict,
        ]);

        // The product stays a draft through the rewrite. It has no business
        // being anywhere a human picks work up from until it passes.
        $task->product->update(['status' => ProductStatus::Draft]);
    }

    private function failQa(GenerationTask $task, QaReport $report): void
    {
        $this->transition($task, TaskStatus::QaFailed, [
            'qa_result' => $report->raw,
            'qa_verdict' => $report->verdict,
            'last_error' => 'Failed quality control after '.$task->qa_round.' revision(s): '.$report->summary(),
            'completed_at' => now(),
        ]);

        $task->product->update(['status' => ProductStatus::QaFailed]);
    }

    /**
     * Store a rewritten document and send it back for re-audit. The round
     * counter goes up here, which is what eventually ends the loop.
     */
    public function storeRevision(GenerationTask $task, LlmResult $result): Product
    {
        return DB::transaction(function () use ($task, $result) {
            $product = $task->product;

            $product->update(['body' => $result->text]);

            $this->transition($task, TaskStatus::QaRunning, ['qa_round' => $task->qa_round + 1]);

            Audit::of($task)
                ->change('qa_round', $task->qa_round - 1, $task->qa_round, 'Revision round')
                ->with(['product' => $product->code])
                ->log('document rewritten after a failed QA audit (round '.$task->qa_round.')');

            return $product;
        });
    }

    /**
     * A human overrules an exhausted QA failure and takes the document on
     * themselves. It goes straight into their hands — `in_proofreading`, with
     * them named as the proofreader — rather than back onto the board, so it
     * is never unclear who decided to keep it.
     */
    public function overrideQaFailure(GenerationTask $task, User $proofreader, string $reason): void
    {
        if ($task->status !== TaskStatus::QaFailed) {
            throw new InvalidTaskTransitionException($task->status, TaskStatus::InProofreading);
        }

        DB::transaction(function () use ($task, $proofreader, $reason): void {
            // Written directly rather than through transition(): `qa_failed`
            // has no legal transition, by design — see TaskStatus. This is the
            // single sanctioned way out of it, and the guard above is the one
            // that matters.
            $task->update([
                'status' => TaskStatus::InProofreading,
                'proofreader_id' => $proofreader->id,
                'proofread_at' => now(),
                'completed_at' => null,
            ]);

            $task->product->update(['status' => ProductStatus::InProofreading]);

            Audit::of($task)->by($proofreader)
                ->change('status', TaskStatus::QaFailed, TaskStatus::InProofreading)
                ->with(['reason' => $reason, 'qaVerdict' => $task->qa_verdict?->value])
                ->log('QA failure overridden; document taken into proofreading');
        });
    }

    /**
     * Put a document the gate is holding back on the board, unassigned.
     *
     * Distinct from overrideQaFailure(), and deliberately so. An override is
     * one person reading one audit, disagreeing with it, and taking that
     * document on — so it lands in their hands as `in_proofreading` with their
     * name on it. This is the administrative counterpart: the gate held a batch
     * it should not have, so the batch goes back to `awaiting_proofreading` as
     * though it had never been held, for whoever picks it up next.
     *
     * The verdict and the audit report are left on the task on purpose. The
     * document is being released, not absolved, and the proofreader should see
     * what the auditor objected to.
     */
    public function releaseQaFailure(GenerationTask $task, User $actor, string $reason): void
    {
        if ($task->status !== TaskStatus::QaFailed) {
            throw new InvalidTaskTransitionException($task->status, TaskStatus::AwaitingProofreading);
        }

        DB::transaction(function () use ($task, $actor, $reason): void {
            // Written directly rather than through transition(): `qa_failed`
            // has no legal transition, by design — see TaskStatus. The guard
            // above is the one that matters.
            $task->update([
                'status' => TaskStatus::AwaitingProofreading,
                // failQa() stamped this when it gave up on the document; the
                // task is live again, so it is no longer completed.
                'completed_at' => null,
                'last_error' => null,
            ]);

            $task->product->update(['status' => ProductStatus::AwaitingProofreading]);

            Audit::of($task)->by($actor)
                ->change('status', TaskStatus::QaFailed, TaskStatus::AwaitingProofreading)
                ->with([
                    'reason' => $reason,
                    'qaVerdict' => $task->qa_verdict?->value,
                    'qaRound' => $task->qa_round,
                    'product' => $task->product?->code,
                ])
                ->log('QA hold released; document returned to the proofreading board');
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

        Audit::of($task)->by($proofreader)
            ->change('status', TaskStatus::AwaitingProofreading, TaskStatus::InProofreading)
            ->with(['product' => $task->product?->code])
            ->log('proofreading opened');
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
        // With the redaction pass off, proofreading is the whole editorial
        // review and approves the product outright.
        $redacting = (bool) config('publishing.redaction');
        $previousBody = (string) $task->product?->body;

        DB::transaction(function () use ($task, $proofreader, $attributes, $redacting): void {
            $this->transition(
                $task,
                $redacting ? TaskStatus::AwaitingRedaction : TaskStatus::Approved,
                ['proofreader_id' => $proofreader->id, 'proofread_at' => now()],
            );

            $body = (string) Arr::get($attributes, 'body');

            $task->product->update([
                'body' => $body,
                // Null would leave the product approved but permanently unable
                // to release, since the FIFO queue skips products without one.
                // Keep whatever is already there rather than clear it.
                'abstract' => $this->abstracts->extract($body) ?? $task->product->abstract,
                'status' => $redacting ? ProductStatus::AwaitingRedaction : ProductStatus::Approved,
                // Stamped here when there is no redaction step to stamp it, and
                // it is what the FIFO release queue orders by.
                ...($redacting ? [] : ['approved_at' => now()]),
            ]);
        });

        Audit::of($task)->by($proofreader)
            ->change('status', TaskStatus::InProofreading, $redacting ? TaskStatus::AwaitingRedaction : TaskStatus::Approved)
            // The bodies themselves are the product, not an audit-log entry —
            // the log records that the document was edited and by how much.
            ->change(
                'body_length',
                strlen($previousBody),
                strlen((string) $task->product->body),
                'Document length (characters)',
            )
            ->with(['product' => $task->product?->code])
            ->log($redacting ? 'document proofread' : 'document proofread and approved');
    }

    /**
     * Proofreading stage 2: the redacted document, reviewed separately from the
     * full one. Completing it approves the product, which then joins the FIFO
     * release queue — approval no longer publishes (FR-28/29, §6).
     */
    public function submitRedaction(GenerationTask $task, User $redactor, string $redactedBody): void
    {
        // Redaction may only act on a task that has completed proofreading.
        // Checking `awaiting_redaction` explicitly rather than relying on the
        // transition map: with publishing.redaction off, `in_proofreading ->
        // approved` is legal, so the map alone would let this approve a product
        // whose body and abstract were never submitted. With the pass off no
        // task ever reaches `awaiting_redaction`, so this always refuses.
        if ($task->status !== TaskStatus::AwaitingRedaction) {
            throw new InvalidTaskTransitionException($task->status, TaskStatus::Approved);
        }

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

        Audit::of($task)->by($redactor)
            ->change('status', TaskStatus::AwaitingRedaction, TaskStatus::Approved)
            ->change('redaction_approved', false, true)
            ->with(['product' => $task->product?->code])
            ->log('redaction approved');
    }

    /**
     * Approve without a redaction pass, for products that do not need one.
     * Queues the product for release; it does not go live here.
     */
    public function approve(GenerationTask $task, User $approver): void
    {
        $previousStatus = $task->status;

        DB::transaction(function () use ($task): void {
            // State first, so a task approved from the wrong place still says
            // so; then the editorial gate.
            $this->transition($task, TaskStatus::Approved);

            // Nothing reaches subscribers that a human has not edited. With the
            // redaction pass off, the status map alone would allow approving
            // straight from `in_proofreading` — that is, publishing the model's
            // raw output — so the proofread submission itself is the gate. The
            // throw rolls the transition back.
            if ($task->proofread_at === null) {
                throw new ProductNotProofreadException;
            }

            $product = $task->product;

            $product->update([
                'status' => ProductStatus::Approved,
                'approved_at' => now(),
                // An approved product with no abstract can never be released:
                // the FIFO queue skips it, silently. Derive one from the body
                // rather than let it sit in the queue for good.
                'abstract' => $product->abstract ?? $this->abstracts->extract((string) $product->body),
            ]);
        });

        Audit::of($task)->by($approver)
            ->change('status', $previousStatus, TaskStatus::Approved)
            ->with(['product' => $task->product?->code])
            ->log('product approved');
    }

    public function reject(GenerationTask $task, User $reviewer, string $note): void
    {
        $previousStatus = $task->status;

        DB::transaction(function () use ($task, $note): void {
            $this->transition($task, TaskStatus::Rejected, ['rejection_note' => $note]);

            $task->product?->update(['status' => ProductStatus::Rejected]);
        });

        Audit::of($task)->by($reviewer)
            ->change('status', $previousStatus, TaskStatus::Rejected)
            ->with(['note' => $note, 'product' => $task->product?->code])
            ->log('product rejected');
    }

    /**
     * Put a failed task back into the pipeline at the stage it actually
     * reached, rather than starting it over.
     *
     * The distinction is money. A task that died during its QA audit has a
     * finished document already written and paid for; restarting it would
     * re-run generation and throw that away. A task that died before the
     * document existed has nothing to resume, so it goes back to the start.
     *
     * Returns the status it was put into, so the caller can say which happened.
     */
    public function retryFailed(GenerationTask $task, User $actor, string $reason): TaskStatus
    {
        if ($task->status !== TaskStatus::Failed) {
            throw new InvalidTaskTransitionException($task->status, TaskStatus::Queued);
        }

        // A body is the evidence that generation finished. Without one there is
        // nothing to audit, whatever the task got as far as recording.
        $resumable = $task->product !== null && filled($task->product->body);
        $target = $resumable ? TaskStatus::QaRunning : TaskStatus::Queued;

        DB::transaction(function () use ($task, $actor, $reason, $target, $resumable): void {
            // Written directly: `failed` is terminal by design — see
            // TaskStatus. The guard above is the one that matters.
            $task->update([
                'status' => $target,
                'last_error' => null,
                'completed_at' => null,
            ]);

            // Draft either way: it is back in the pipeline and has no business
            // anywhere a human picks work up from until it passes the audit.
            $task->product?->update(['status' => ProductStatus::Draft]);

            Audit::of($task)->by($actor)
                ->change('status', TaskStatus::Failed, $target)
                ->with([
                    'reason' => $reason,
                    'resumedAt' => $resumable ? 'qa' : 'generation',
                    'product' => $task->product?->code,
                ])
                ->log('failed task requeued at the '.($resumable ? 'audit' : 'generation').' stage');
        });

        return $target;
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
