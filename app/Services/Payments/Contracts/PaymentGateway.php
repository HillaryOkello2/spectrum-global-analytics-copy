<?php

namespace App\Services\Payments\Contracts;

use App\Exceptions\Domain\PaymentCallbackMismatchException;
use App\Models\Payment;
use App\Services\Payments\DTOs\CallbackResult;
use App\Services\Payments\DTOs\PaymentInitiation;
use Illuminate\Http\Request;

/**
 * Gateway-agnostic payment contract. The concrete PGW driver will implement this
 * once the PGW API docs are available; FakeGatewayDriver covers local/UAT.
 */
interface PaymentGateway
{
    /**
     * Initiate a payment (MPESA STK push or card checkout) for a pending Payment.
     */
    public function initiate(Payment $payment): PaymentInitiation;

    /**
     * Verify and parse an incoming gateway callback request.
     *
     * @throws PaymentCallbackMismatchException
     */
    public function parseCallback(Request $request): CallbackResult;
}
