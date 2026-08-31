<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SummarisesRatings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full product body — only ever returned after EntitlementService grants
 * full access (FR-18, FR-32).
 */
class ProductResource extends JsonResource
{
    use SummarisesRatings;

    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'code' => $this->code,
            'title' => $this->title,
            'byline' => $this->byline,
            'abstract' => $this->abstract,
            'body' => $this->body,
            'locked' => false,
            ...$this->ratingSummary(),
            'publishedAt' => $this->published_at?->format('Y-m-d H:i:s'),
            'component' => new ComponentResource($this->whenLoaded('component')),
        ];
    }
}
