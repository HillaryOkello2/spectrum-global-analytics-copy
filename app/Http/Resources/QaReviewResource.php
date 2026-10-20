<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One QA audit — the first draft's, or a re-audit after a rewrite. The
 * proofreader needs the whole series to see what was wrong and whether the
 * model actually fixed it.
 */
class QaReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'round' => $this->round,
            'verdict' => $this->verdict->value,
            'failedSections' => $this->failed_sections ?? [],
            'rerunInstructions' => $this->rerun_instructions,
            'report' => $this->report,
            'auditedAt' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
