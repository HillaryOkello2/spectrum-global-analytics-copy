<?php

namespace App\Http\Controllers\Api\V1\Public\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;

/**
 * @group Payments
 *
 * Poll a payment while it settles (§18.3). Public, because a paid signup has
 * no token until its payment succeeds. The payment's publicId is an
 * unguessable UUID given only to the payer, and the response holds nothing
 * personal.
 */
class PaymentStatusController extends Controller
{
    public function __invoke(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment);
    }
}
