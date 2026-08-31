<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SummarisesRatings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The redacted document, served where a subscriber's tier withholds the full
 * one but a redaction has been proofread and approved. Like the preview
 * resource, this never exposes `body` — the unredacted document cannot leak
 * through this path (R-04).
 */
class ProductRedactedResource extends JsonResource
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
            'redactedBody' => $this->redacted_body,
            ...$this->ratingSummary(),
            // Locked: this is not the full document. The frontend still shows
            // the upgrade prompt, with readable content behind it.
            'locked' => true,
            'redacted' => true,
            'publishedAt' => $this->published_at?->format('Y-m-d H:i:s'),
            'component' => new ComponentResource($this->whenLoaded('component')),
        ];
    }
}
