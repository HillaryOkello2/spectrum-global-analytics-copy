<?php

namespace App\Http\Resources;

use App\Services\Billing\ChargeCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

class TierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'price' => $this->price,
            'currency' => $this->currency,
            'charge' => $this->charge(),
            'billingPeriod' => $this->billing_period,
            'isActive' => $this->is_active,
            'sortOrder' => $this->sort_order,
            // Admin listings count them; the public pricing page does not.
            'subscribersCount' => $this->whenCounted('subscriptions', fn ($count) => (int) $count),
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

    /**
     * What the payer will actually be asked for, when that differs from the
     * list price (a USD tier charged in KES), so the pricing page can show it
     * before M-Pesa does. Null when nothing is converted, and when no rate is
     * configured yet: the catalogue must not break over a billing setting.
     *
     * @return array{amount: string, currency: string}|null
     */
    private function charge(): ?array
    {
        if ($this->resource->isFree()) {
            return null;
        }

        try {
            $charge = app(ChargeCalculator::class)->forTier($this->resource);
        } catch (RuntimeException) {
            return null;
        }

        return $charge->exchangeRate === null
            ? null
            : ['amount' => $charge->amount, 'currency' => $charge->currency];
    }
}
