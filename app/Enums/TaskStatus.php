<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Queued = 'queued';
    case Generating = 'generating';
    case QaRunning = 'qa_running';
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
            self::QaRunning => [self::AwaitingProofreading, self::Failed],
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
            self::Published, self::Failed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
