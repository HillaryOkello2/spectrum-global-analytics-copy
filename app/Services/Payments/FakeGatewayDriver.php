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
 */
class FakeGatewayDriver implements PaymentGateway
{
    public function initiate(Payment $payment): PaymentInitiation
    {
        return new PaymentInitiation(
            gatewayRef: 'FAKE-'.Str::upper(Str::random(12)),
            instructions: [
                'type' => $payment->method->value,
                'message' => 'Fake gateway: POST the callback endpoint with this gatewayRef to complete payment.',
            ],
        );
    }

    public function parseCallback(Request $request): CallbackResult
    {
        $gatewayRef = $request->input('gateway_ref');

        if (! is_string($gatewayRef) || $gatewayRef === '') {
            throw new PaymentCallbackMismatchException('Missing gateway_ref in callback payload.');
        }

        return new CallbackResult(
            gatewayRef: $gatewayRef,
            successful: $request->input('result') === 'success',
            raw: $request->all(),
        );
    }
}
