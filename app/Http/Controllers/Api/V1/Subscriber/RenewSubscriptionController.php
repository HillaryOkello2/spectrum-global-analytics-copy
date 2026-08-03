<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriber\RenewSubscriptionRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SubscriptionResource;
use App\Services\Billing\SubscriptionChangeService;
use Illuminate\Http\JsonResponse;

/**
 * @group Subscriber Portal
 *
 * Renew the current subscription for another month on the same tier. Freemium
 * renews immediately; paid tiers return payment instructions to complete via the
 * gateway (then poll GET /payments/{payment}/status).
 */
class RenewSubscriptionController extends Controller
{
    public function __invoke(RenewSubscriptionRequest $request, SubscriptionChangeService $service): JsonResponse
    {
        $method = $request->validated('payment_method')
            ? PaymentMethod::from($request->validated('payment_method'))
            : null;

        $result = $service->renew($request->user(), $method);

        if (! $result->requiresPayment()) {
            return response()->json([
                'message' => 'Subscription renewed.',
                'data' => ['subscription' => new SubscriptionResource($result->subscription->load('tier'))],
            ]);
        }

        return response()->json([
            'message' => 'Complete payment to renew your subscription.',
            'data' => [
                'payment' => new PaymentResource($result->payment),
                'instructions' => $result->paymentInitiation->instructions,
            ],
        ], 202);
    }
}
