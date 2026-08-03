<?php

namespace App\Services\Billing\DTOs;

use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Payments\DTOs\PaymentInitiation;

readonly class SubscriptionChangeResult
{
    public function __construct(
        public Subscription $subscription,
        public ?Payment $payment = null,
        public ?PaymentInitiation $paymentInitiation = null,
    ) {}

    /**
     * True when the change needs a gateway payment before it takes effect
     * (paid renewal or any upgrade). False when applied immediately (free tier renewal).
     */
    public function requiresPayment(): bool
    {
        return $this->payment !== null;
    }
}
