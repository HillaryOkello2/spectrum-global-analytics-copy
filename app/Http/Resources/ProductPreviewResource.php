<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SummarisesRatings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Gated preview (FR-09): abstract + lock flag ONLY. This resource never exposes
 * `body` or `redactedBody`, so gated content physically cannot leak through the
 * preview path (R-04).
 */
class ProductPreviewResource extends JsonResource
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
            'locked' => true,
            ...$this->ratingSummary(),
            'publishedAt' => $this->published_at?->format('Y-m-d H:i:s'),
            'component' => new ComponentResource($this->whenLoaded('component')),
        ];
    }
}
