<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'status' => $this->status->value,
            'startsAt' => $this->starts_at?->format('Y-m-d H:i:s'),
            'endsAt' => $this->ends_at?->format('Y-m-d H:i:s'),
            'tier' => new TierResource($this->whenLoaded('tier')),
        ];
    }
}
