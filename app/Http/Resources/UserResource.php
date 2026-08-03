<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'status' => $this->status->value,
            'roles' => $this->getRoleNames(),
            // Effective permissions (via roles + direct) — use these to gate UI.
            'permissions' => $this->effectivePermissions(),
            // Granted on this user alone; what the permissions editor edits.
            'directPermissions' => $this->getDirectPermissions()->pluck('name'),
            'joinedAt' => $this->created_at?->format('Y-m-d H:i:s'),
            'subscription' => new SubscriptionResource($this->whenLoaded('activeSubscription')),
        ];
    }
}
