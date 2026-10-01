<?php

namespace App\Services\Payments;

use App\Exceptions\Domain\PaymentCallbackMismatchException;
use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\DTOs\CallbackResult;
use App\Services\Payments\DTOs\PaymentInitiation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Local/UAT gateway: accepts every initiation and treats a callback as successful
 * when the payload contains `"result": "success"`. Never enable in production.
 *
 * A callback may also carry `amount` and `transaction_code`, to exercise the
 * amount check and receipt storage the way PGW's callback does.
 */
class FakeGatewayDriver implements PaymentGateway
{
    public function newReference(): string
    {
        return 'FAKE-'.Str::upper(Str::random(12));
    }

    public function initiate(Payment $payment): PaymentInitiation
    {
        return new PaymentInitiation([
            'type' => $payment->method->value,
            'message' => 'Fake gateway: POST the callback endpoint with this gatewayRef to complete payment.',
        ]);
    }

    public function parseCallback(Request $request): CallbackResult
    {
        $gatewayRef = $request->input('gateway_ref');

        if (! is_string($gatewayRef) || $gatewayRef === '') {
            throw new PaymentCallbackMismatchException('Missing gateway_ref in callback payload.');
        }

        $amount = $request->input('amount');
        $transactionCode = $request->input('transaction_code');

        return new CallbackResult(
            gatewayRef: $gatewayRef,
            successful: $request->input('result') === 'success',
            raw: $request->all(),
            amount: is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : null,
            transactionCode: is_string($transactionCode) && $transactionCode !== '' ? $transactionCode : null,
        );
    }
}
