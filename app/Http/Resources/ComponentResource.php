<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'code' => $this->code,
            'batch' => $this->batch,
            'isTransactional' => $this->is_transactional,
            'sortOrder' => $this->sort_order,
            // Present only where the endpoint counted it — never on a component
            // nested inside a product or topic payload.
            'productsCount' => $this->whenCounted('products', fn ($count) => (int) $count),

            // --- Generation metadata (admin only) -----------------------------
            // This resource is also served on the public catalogue, so the prompt
            // metadata is gated: `variables` tells the admin topic form which
            // placeholders to collect, and knowing a component's cadence or
            // reference token is of no use to a visitor.
            ...$this->when(
                $request->user()?->can('manage topics') ?? false,
                fn () => [
                    'refCode' => $this->ref_code,
                    'variables' => $this->variables ?? [],
                    'generationFrequency' => $this->generation_frequency?->value,
                ],
                []
            ),
        ];
    }
}
