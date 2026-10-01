<?php

namespace App\Services\Payments\Contracts;

use App\Exceptions\Domain\PaymentCallbackMismatchException;
use App\Exceptions\Domain\PaymentCallbackUnauthorizedException;
use App\Models\Payment;
use App\Services\Payments\DTOs\CallbackResult;
use App\Services\Payments\DTOs\PaymentInitiation;
use App\Services\Payments\Exceptions\PaymentInitiationFailedException;
use Illuminate\Http\Request;

/**
 * Gateway-agnostic payment contract: PgwGatewayDriver in production,
 * FakeGatewayDriver for local/UAT.
 */
interface PaymentGateway
{
    /**
     * A new, unique reference for a payment. It is saved on the payment before
     * initiate() runs, so no callback can arrive for a reference not yet stored.
     */
    public function newReference(): string;

    /**
     * Initiate a payment (M-Pesa STK push or card checkout) for a pending
     * Payment, under its `gateway_ref`.
     *
     * @throws PaymentInitiationFailedException
     */
    public function initiate(Payment $payment): PaymentInitiation;

    /**
     * Verify and parse an incoming gateway callback request.
     *
     * @throws PaymentCallbackMismatchException
     * @throws PaymentCallbackUnauthorizedException
     */
    public function parseCallback(Request $request): CallbackResult;
}
