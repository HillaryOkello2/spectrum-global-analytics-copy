<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * @group Subscriber Portal
 *
 * Payment status polling while awaiting the MPESA STK / card callback (§18.3).
 */
class PaymentStatusController extends Controller
{
    public function __invoke(Request $request, Payment $payment): PaymentResource
    {
        abort_unless($payment->user_id === $request->user()->id, 404);

        return new PaymentResource($payment);
    }
}
