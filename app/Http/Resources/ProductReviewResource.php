<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * The proofreader's view of a product: everything the subscriber-facing resource
 * returns, plus the redacted document and workflow state.
 *
 * Admin-only — it exposes all three content levels at once, which is exactly
 * what the two-stage review needs and exactly what must never reach a
 * subscriber.
 */
class ProductReviewResource extends ProductResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'redactedBody' => $this->redacted_body,
            'redactionApproved' => $this->redaction_approved,
            'status' => $this->status->value,
            'isHidden' => $this->is_hidden,
            'approvedAt' => $this->approved_at?->format('Y-m-d H:i:s'),
        ];
    }
}
