<?php

namespace App\Http\Controllers\Api\V1\Public\Payments;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\RetryPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\Payments\PaymentRetryService;
use Illuminate\Http\JsonResponse;

/**
 * @group Payments
 *
 * Retry a failed payment: declined, expired, or never started. Public for the
 * same reason as the status poll, and throttled harder, since every call can
 * send an M-Pesa prompt.
 */
class RetryPaymentController extends Controller
{
    public function __invoke(RetryPaymentRequest $request, Payment $payment, PaymentRetryService $service): JsonResponse
    {
        $method = $request->validated('payment_method')
            ? PaymentMethod::from($request->validated('payment_method'))
            : null;

        $started = $service->retry($payment, $method, $request->validated('phone'));

        return response()->json([
            'message' => 'Complete payment to continue.',
            'data' => [
                'payment' => new PaymentResource($started->payment),
                'instructions' => $started->initiation->instructions,
            ],
        ], 202);
    }
}
