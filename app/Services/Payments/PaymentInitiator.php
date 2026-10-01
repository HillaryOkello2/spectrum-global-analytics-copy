<?php

namespace App\Services\Payments;

use App\Enums\PaymentFailureReason;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Billing\ChargeCalculator;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\DTOs\PaymentInitiation;
use App\Services\Payments\DTOs\StartedPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place a payment is raised and handed to the gateway. Signups,
 * renewals, upgrades and retries all come through here.
 */
class PaymentInitiator
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly ChargeCalculator $charges,
    ) {}

    public function start(
        User $user,
        ?Subscription $subscription,
        Model $payable,
        SubscriptionTier $tier,
        PaymentMethod $method,
        ?string $phone = null,
    ): StartedPayment {
        $payment = $this->raise($user, $subscription, $payable, $tier, $method, $phone);

        return new StartedPayment($payment, $this->send($payment));
    }

    /**
     * Record the pending payment, gateway reference included, before the
     * gateway hears of it: a callback that outruns the gateway's own response
     * must still find its payment.
     */
    public function raise(
        User $user,
        ?Subscription $subscription,
        Model $payable,
        SubscriptionTier $tier,
        PaymentMethod $method,
        ?string $phone = null,
    ): Payment {
        $charge = $this->charges->forTier($tier);

        return Payment::create([
            'user_id' => $user->id,
            'subscription_id' => $subscription?->id,
            'payable_type' => $payable::class,
            'payable_id' => $payable->id,
            'method' => $method,
            'phone' => $method === PaymentMethod::Mpesa ? ($phone ?? $user->phone) : null,
            'amount' => $charge->amount,
            'currency' => $charge->currency,
            'list_amount' => $charge->listAmount,
            'list_currency' => $charge->listCurrency,
            'exchange_rate' => $charge->exchangeRate,
            'status' => PaymentStatus::Pending,
            'gateway' => config('payments.gateway'),
            'gateway_ref' => $this->gateway->newReference(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /**
     * A gateway that won't start the payment fails the payment, not the
     * request. The signup or change is already committed, so the payer gets
     * the failed payment back and can retry it. Rolling the signup back
     * instead could orphan an M-Pesa prompt already on their phone: a timeout
     * doesn't mean PGW never sent it.
     */
    public function send(Payment $payment): PaymentInitiation
    {
        try {
            return $this->gateway->initiate($payment);
        } catch (Throwable $e) {
            report($e);

            $payment->update([
                'status' => PaymentStatus::Failed,
                'failure_reason' => PaymentFailureReason::GatewayError,
            ]);

            return new PaymentInitiation([
                'type' => $payment->method->value,
                'message' => 'The payment could not be started. Try again, or choose another payment method.',
            ]);
        }
    }
}
