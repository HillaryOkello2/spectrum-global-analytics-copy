<?php

namespace App\Services\Generation\DTOs;

use App\Enums\QaVerdict;

/**
 * A parsed QA audit report: the verdict, the sections that failed, and the
 * auditor's correction notes.
 */
readonly class QaReport
{
    /**
     * @param  array<int, string>  $failedSections
     */
    public function __construct(
        public QaVerdict $verdict,
        public array $failedSections,
        public ?string $rerunInstructions,
        public string $raw,
    ) {}

    public function isPass(): bool
    {
        return $this->verdict->isPass();
    }

    /**
     * A one-line summary for the task board and the audit log — "RED LIGHT
     * (sections 1, 4)" reads better on a row than the whole report.
     */
    public function summary(): string
    {
        if ($this->failedSections === []) {
            return $this->verdict->label();
        }

        return $this->verdict->label().' — failed: '.implode('; ', $this->failedSections);
    }
}
