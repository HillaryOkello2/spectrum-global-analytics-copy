<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SummarisesRatings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Catalog listing entry: title + short excerpt only, never the body (FR-05, §14.5).
 */
class ProductListResource extends JsonResource
{
    use SummarisesRatings;

    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'code' => $this->code,
            'title' => $this->title,
            'byline' => $this->byline,
            'excerpt' => Str::limit((string) $this->abstract, 160),
            ...$this->ratingSummary(),
            'publishedAt' => $this->published_at?->format('Y-m-d H:i:s'),
            'component' => new ComponentResource($this->whenLoaded('component')),
        ];
    }
}
