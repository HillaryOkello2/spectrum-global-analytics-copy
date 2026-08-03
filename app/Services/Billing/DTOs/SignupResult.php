<?php

namespace App\Services\Billing\DTOs;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\DTOs\PaymentInitiation;

readonly class SignupResult
{
    public function __construct(
        public User $user,
        public Subscription $subscription,
        public ?Payment $payment = null,
        public ?PaymentInitiation $paymentInitiation = null,
        public ?string $token = null,
    ) {}

    public function requiresPayment(): bool
    {
        return $this->payment !== null;
    }
}
