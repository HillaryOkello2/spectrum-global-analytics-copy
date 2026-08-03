<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use App\Models\SubscriptionTier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of a payment (FR-42). Deliberately separate from PaymentResource,
 * which is what subscribers receive: this one carries the payer, the gateway and
 * its reference, and the invoice number — none of which belong in a
 * subscriber-facing payload.
 *
 * `raw_callback` is never exposed. It is the verbatim gateway payload and can
 * contain payer PII and provider-side identifiers.
 */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'gateway' => $this->gateway,
            'gatewayRef' => $this->gateway_ref,
            'what' => $this->describePayable(),
            'paidAt' => $this->paid_at?->format('Y-m-d H:i:s'),
            'createdAt' => $this->created_at->format('Y-m-d H:i:s'),
            'payer' => $this->whenLoaded('user', fn () => [
                'publicId' => $this->user->public_id,
                'name' => $this->user->full_name,
                'email' => $this->user->email,
            ]),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice === null ? null : [
                'publicId' => $this->invoice->public_id,
                'number' => $this->invoice->number,
                'issueDate' => $this->invoice->issue_date->format('Y-m-d'),
            ]),
        ];
    }

    /**
     * What the money was for, in one readable line. The payable morph is a
     * Subscription for a signup or renewal and a SubscriptionTier for an
     * upgrade, so the type alone does not tell the story.
     */
    private function describePayable(): string
    {
        return match ($this->payable_type) {
            Subscription::class => 'Subscription',
            SubscriptionTier::class => 'Tier upgrade',
            default => class_basename((string) $this->payable_type) ?: 'Payment',
        };
    }
}
