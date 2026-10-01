<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'method' => $this->method->value,
            // What the payer is charged, in the currency they pay in...
            'amount' => $this->amount,
            'currency' => $this->currency,
            // ...and the tier price it was converted from. The same as amount
            // and currency when nothing was converted.
            'listAmount' => $this->list_amount ?? $this->amount,
            'listCurrency' => $this->list_currency ?? $this->currency,
            'status' => $this->status->value,
            'failureReason' => $this->failure_reason?->value,
            'paidAt' => $this->paid_at?->format('Y-m-d H:i:s'),
        ];
    }
}
