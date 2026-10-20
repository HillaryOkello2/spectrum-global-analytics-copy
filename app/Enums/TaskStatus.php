<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Queued = 'queued';
    case Generating = 'generating';
    case QaRunning = 'qa_running';
    // The document failed its QA audit and is being rewritten by the same
    // model, with the audit report attached. See ReviseProductJob.
    case Revising = 'revising';
    // Out of revision rounds and still failing. A terminal state for the
    // pipeline: the product never reaches the vault from here, only a human
    // overriding the verdict can move it on.
    case QaFailed = 'qa_failed';
    case AwaitingProofreading = 'awaiting_proofreading';
    case InProofreading = 'in_proofreading';
    case AwaitingRedaction = 'awaiting_redaction';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Published = 'published';
    case Failed = 'failed';

    /**
     * Valid transitions for the task board state machine (FR-27/28/29, §18.4).
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Generating, self::Failed],
            self::Generating => [self::QaRunning, self::Failed],
            // A QA pass has four outcomes now: it passed, it failed and there
            // is a revision round left, it failed and there is not, or the
            // audit itself could not be run.
            self::QaRunning => [self::AwaitingProofreading, self::Revising, self::QaFailed, self::Failed],
            // A revision is re-audited; it is not trusted on its own.
            self::Revising => [self::QaRunning, self::Failed],

            self::AwaitingProofreading => [self::InProofreading],
            // Proofreading hands over to a separate redaction pass, and only
            // then can the task be approved. With publishing.redaction off there
            // is no such pass and proofreading approves directly.
            //
            // Config-dependent rather than allowing both: listing Approved
            // alongside AwaitingRedaction would make `/redact` legal straight
            // from `in_proofreading`, approving a product whose body and
            // abstract were never submitted. Exactly one path is legal at a time.
            self::InProofreading => config('publishing.redaction')
                ? [self::AwaitingRedaction, self::Rejected]
                : [self::Approved, self::Rejected],
            self::AwaitingRedaction => [self::Approved, self::Rejected],
            self::Rejected => [self::InProofreading],
            // Publication is not immediate — products:release takes approved
            // products live oldest-first (§6, FIFO).
            self::Approved => [self::Published],
            // Terminal. `qa_failed` is deliberately not routed to
            // `in_proofreading` here: that would let POST /tasks/{task}/open
            // pick a rejected document straight off the board and walk it
            // through as though it had passed. The one way out is
            // GenerationPipeline::overrideQaFailure(), which demands a named
            // proofreader and a reason.
            self::Published, self::Failed, self::QaFailed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
