<?php

namespace App\Services\Payments\DTOs;

use App\Models\Payment;

/**
 * A payment raised and handed to the gateway. The payment may already be
 * `failed` if the gateway would not start it; the instructions then say so.
 */
readonly class StartedPayment
{
    public function __construct(
        public Payment $payment,
        public PaymentInitiation $initiation,
    ) {}
}
