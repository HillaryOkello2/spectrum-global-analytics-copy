<?php

namespace App\Services\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\Domain\PaymentNotRetryableException;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Services\Payments\DTOs\StartedPayment;
use Illuminate\Support\Facades\DB;

/**
 * A fresh attempt at a payment that failed: declined, expired, or never
 * started. It is keyed on the failed payment, so a paid signup with no login
 * yet can retry too.
 */
class PaymentRetryService
{
    public function __construct(
        private readonly PaymentInitiator $payments,
    ) {}

    public function retry(Payment $payment, ?PaymentMethod $method = null, ?string $phone = null): StartedPayment
    {
        // Lock the subscription so two retries of one payment can't both pass
        // the latest-payment check and raise two new payments.
        $retry = DB::transaction(function () use ($payment, $method, $phone): Payment {
            $subscription = Subscription::query()->lockForUpdate()->find($payment->subscription_id);

            $this->ensureRetryable($payment->refresh(), $subscription);

            return $this->payments->raise(
                user: $payment->user,
                subscription: $subscription,
                payable: $payment->payable,
                tier: $payment->payable instanceof SubscriptionTier ? $payment->payable : $subscription->tier,
                method: $method ?? $payment->method,
                phone: $phone ?? $payment->phone,
            );
        });

        // After the commit, for the same reason as at signup: see PaymentInitiator::send().
        return new StartedPayment($retry, $this->payments->send($retry));
    }

    private function ensureRetryable(Payment $payment, ?Subscription $subscription): void
    {
        if ($payment->status === PaymentStatus::Pending) {
            throw new PaymentNotRetryableException('This payment is still pending. Wait for it to complete or expire.');
        }

        if ($payment->status === PaymentStatus::Successful) {
            throw new PaymentNotRetryableException('This payment has already been completed.');
        }

        if ($subscription === null) {
            throw new PaymentNotRetryableException;
        }

        // Only the latest attempt: an older failure has already been retried.
        if ($subscription->payments()->latest('id')->value('id') !== $payment->id) {
            throw new PaymentNotRetryableException('A newer payment exists for this subscription. Retry that one instead.');
        }
    }
}
