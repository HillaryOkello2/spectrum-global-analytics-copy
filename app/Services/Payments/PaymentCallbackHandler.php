<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\Domain\PaymentCallbackMismatchException;
use App\Models\Payment;
use App\Services\Billing\SubscriptionActivator;
use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Http\Request;

class PaymentCallbackHandler
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionActivator $activator,
    ) {}

    /**
     * Idempotent callback processing (§13.2 step 3-4): a payment already in a
     * terminal state is returned untouched, so duplicate or replayed callbacks
     * are harmless.
     */
    public function handle(Request $request): Payment
    {
        $result = $this->gateway->parseCallback($request);

        $payment = Payment::query()
            ->where('gateway_ref', $result->gatewayRef)
            ->first();

        if ($payment === null) {
            throw new PaymentCallbackMismatchException;
        }

        if ($payment->status !== PaymentStatus::Pending) {
            return $payment;
        }

        if (! $result->successful) {
            $payment->update([
                'status' => PaymentStatus::Failed,
                'raw_callback' => $result->raw,
            ]);

            return $payment;
        }

        $this->activator->activate($payment, $result->raw);

        return $payment->refresh();
    }
}
