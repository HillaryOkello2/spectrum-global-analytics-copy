<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentCallbackHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Webhooks
 */
class PaymentCallbackController extends Controller
{
    /**
     * Gateway payment confirmation callback (§13.2 step 3). Idempotent —
     * replayed callbacks are acknowledged without side effects.
     */
    public function __invoke(Request $request, PaymentCallbackHandler $handler): JsonResponse
    {
        $payment = $handler->handle($request);

        return response()->json([
            'message' => 'Callback processed.',
            'status' => $payment->status->value,
        ]);
    }
}
