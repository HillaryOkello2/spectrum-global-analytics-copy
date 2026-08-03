<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GenerationTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'lastError' => $this->last_error,
            'qaResult' => $this->qa_result,
            'rejectionNote' => $this->rejection_note,
            'proofreader' => $this->whenLoaded('proofreader', fn () => [
                'publicId' => $this->proofreader->public_id,
                'name' => $this->proofreader->full_name,
            ]),
            'proofreadAt' => $this->proofread_at?->format('Y-m-d H:i:s'),
            'redactor' => $this->whenLoaded('redactor', fn () => [
                'publicId' => $this->redactor->public_id,
                'name' => $this->redactor->full_name,
            ]),
            'redactedAt' => $this->redacted_at?->format('Y-m-d H:i:s'),
            'queuedAt' => $this->queued_at?->format('Y-m-d H:i:s'),
            'completedAt' => $this->completed_at?->format('Y-m-d H:i:s'),
            'topic' => new TopicResource($this->whenLoaded('topic')),
            'product' => new ProductPreviewResource($this->whenLoaded('product')),
        ];
    }
}
