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
        ];
    }
}
