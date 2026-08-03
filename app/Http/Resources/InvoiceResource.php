<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'number' => $this->number,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'issueDate' => $this->issue_date?->format('Y-m-d'),
            'lineItems' => $this->line_items,
        ];
    }
}
