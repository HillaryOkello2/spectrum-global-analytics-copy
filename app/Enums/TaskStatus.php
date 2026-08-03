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
            // Proofreading the abstract + document hands over to a separate
            // redaction pass; only then can the task be approved.
            self::InProofreading => [self::AwaitingRedaction, self::Rejected],
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
