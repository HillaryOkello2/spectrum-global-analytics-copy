<?php

namespace App\Services\Payments;

use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Exceptions\Domain\PaymentCallbackMismatchException;
use App\Models\Payment;
use App\Notifications\PaymentFailed;
use App\Services\Billing\SubscriptionActivator;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\DTOs\CallbackResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentCallbackHandler
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionActivator $activator,
    ) {}

    /**
     * Callback processing (§13.2 step 3-4). Idempotent: a successful payment is
     * returned untouched, so duplicate or replayed callbacks are harmless. The
     * row stays locked throughout, so two copies of one callback arriving
     * together cannot both activate it.
     */
    public function handle(Request $request): Payment
    {
        $result = $this->gateway->parseCallback($request);

        return DB::transaction(function () use ($result): Payment {
            // By our reference first; failing that, by the publicId the
            // gateway echoed back in `meta` — PGW's own sample receiver
            // matches on meta rather than the reference, so both can happen.
            $payment = Payment::query()
                ->when(
                    $result->gatewayRef !== '',
                    fn ($query) => $query->where('gateway_ref', $result->gatewayRef),
                    fn ($query) => $query->whereRaw('1 = 0'),
                )
                ->lockForUpdate()
                ->first();

            if ($payment === null && $result->paymentPublicId !== null) {
                $payment = Payment::query()
                    ->where('public_id', $result->paymentPublicId)
                    ->lockForUpdate()
                    ->first();
            }

            if ($payment === null) {
                throw new PaymentCallbackMismatchException;
            }

            if ($payment->status === PaymentStatus::Successful) {
                return $payment;
            }

            if (! $result->successful) {
                // A failure only ever settles a pending payment.
                if ($payment->status === PaymentStatus::Pending) {
                    $payment->update([
                        'status' => PaymentStatus::Failed,
                        'failure_reason' => PaymentFailureReason::Declined,
                        'raw_callback' => $result->raw,
                    ]);

                    $payment->user->notify(new PaymentFailed($payment));
                }

                return $payment;
            }

            if ($this->isShort($payment, $result)) {
                Log::error('Payment callback paid less than was charged; not activating.', [
                    'gateway_ref' => $payment->gateway_ref,
                    'charged' => $payment->amount,
                    'paid' => $result->amount,
                ]);

                $payment->update([
                    'status' => PaymentStatus::Failed,
                    'failure_reason' => PaymentFailureReason::AmountMismatch,
                    'transaction_code' => $result->transactionCode,
                    'raw_callback' => $result->raw,
                ]);

                $payment->user->notify(new PaymentFailed($payment));

                return $payment;
            }

            // Money moved after this side gave up on the payment: it expired,
            // or the gateway errored but sent the prompt anyway. Deliver what
            // was paid for. A second payment on one subscription is applied as
            // a renewal, so a payer who also retried gets two months, not one.
            if ($payment->status === PaymentStatus::Failed) {
                Log::warning('Late payment success; activating a payment already marked failed.', [
                    'gateway_ref' => $payment->gateway_ref,
                    'failure_reason' => $payment->failure_reason?->value,
                ]);

                activity()
                    ->performedOn($payment)
                    ->withProperties(['failureReason' => $payment->failure_reason?->value])
                    ->log('late payment success after the payment was marked failed');
            }

            $this->activator->activate($payment, $result->raw, $result->transactionCode);

            return $payment->refresh();
        });
    }

    /**
     * Compared in cents, as integers. A gateway that doesn't report the amount
     * can't be checked, so a null amount passes.
     */
    private function isShort(Payment $payment, CallbackResult $result): bool
    {
        if ($result->amount === null) {
            return false;
        }

        return (int) round((float) $result->amount * 100) < (int) round((float) $payment->amount * 100);
    }
}
