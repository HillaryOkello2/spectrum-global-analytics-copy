<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'price' => $this->price,
            'currency' => $this->currency,
            'billingPeriod' => $this->billing_period,
            'allocations' => $this->whenLoaded('allocations', function () {
                return $this->allocations->map(fn ($allocation) => [
                    'componentCode' => $allocation->component->code,
                    'componentName' => $allocation->component->name,
                    'accessType' => $allocation->access_type->value,
                    'monthlyLimit' => $allocation->monthly_limit,
                ]);
            }),
        ];
    }
}
