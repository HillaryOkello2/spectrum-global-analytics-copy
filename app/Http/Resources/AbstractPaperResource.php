<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\EstimatesReadingTime;
use App\Http\Resources\Concerns\SummarisesRatings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in Abstract Papers: an abstract, and enough to find the document
 * it belongs to.
 *
 * Unlike ProductListResource this carries the abstract in full rather than a
 * 160-character excerpt — the abstract is the whole point of this listing, not
 * a teaser for it. It still carries no `body` and no `redactedBody`: opening
 * the full document means calling GET /products/{publicId}, which goes through
 * EntitlementService like every other route to a body (R-04).
 *
 * `product` is the handle the frontend wires its "read the full paper" control
 * to; `component` names which product line the paper came from, since in this
 * listing they are mixed.
 */
class AbstractPaperResource extends JsonResource
{
    use EstimatesReadingTime, SummarisesRatings;

    public function toArray(Request $request): array
    {
        return [
            // The abstract has no identity of its own — it is this product's.
            // Keeping the key named `product` rather than `publicId` keeps the
            // frontend from mistaking it for a separate thing it can open.
            'product' => $this->public_id,
            'code' => $this->code,
            'title' => $this->title,
            'byline' => $this->byline,
            'abstract' => $this->abstract,
            ...$this->ratingSummary(),
            // How long the full document runs, not the abstract.
            ...$this->readingTime(),
            'publishedAt' => $this->published_at?->format('Y-m-d H:i:s'),
            'component' => new ComponentResource($this->whenLoaded('component')),
        ];
    }
}
