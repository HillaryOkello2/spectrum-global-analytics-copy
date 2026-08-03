<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Full task view for proofreading (FR-28, §18.4). Extends the board resource but
 * swaps the gated product preview for the COMPLETE product body, and adds the
 * generating LLM — so the proofreader can review the entire article against the
 * original prompt (on the topic) and the QA result.
 *
 * Admin-only. Never expose this to subscribers (it contains the full body
 * regardless of tier entitlement).
 */
class GenerationTaskDetailResource extends GenerationTaskResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'product' => new ProductReviewResource($this->whenLoaded('product')),
            'llmProvider' => $this->whenLoaded('llmProvider', fn () => [
                'publicId' => $this->llmProvider->public_id,
                'name' => $this->llmProvider->name,
                'vendor' => $this->llmProvider->vendor,
            ]),
        ];
    }
}
