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

            // A downgrade the subscriber has scheduled. Null — the usual case
            // — means the next term runs on the same tier as this one.
            //
            // Loaded unconditionally rather than with whenLoaded(): a key that
            // disappears when the relation happens not to be eager-loaded
            // would have the frontend show "no change scheduled" for a
            // subscription that has one.
            'pendingTier' => $this->pending_tier_id === null
                ? null
                : new TierResource($this->pendingTier),
            // When that change lands — the end of the term already paid for.
            'pendingTierEffectiveAt' => $this->pending_tier_id === null
                ? null
                : $this->ends_at?->format('Y-m-d H:i:s'),
        ];
    }
}
