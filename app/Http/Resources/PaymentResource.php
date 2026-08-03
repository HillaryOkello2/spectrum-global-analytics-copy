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
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'paidAt' => $this->paid_at?->format('Y-m-d H:i:s'),
        ];
    }
}
