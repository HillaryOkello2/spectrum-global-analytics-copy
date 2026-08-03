<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriber\UpgradeSubscriptionRequest;
use App\Http\Resources\PaymentResource;
use App\Models\SubscriptionTier;
use App\Services\Billing\SubscriptionChangeService;
use Illuminate\Http\JsonResponse;

/**
 * @group Subscriber Portal
 *
 * Upgrade to a higher tier, charged at the full price of the new tier. The change
 * takes effect once payment clears (fresh monthly term). Poll
 * GET /payments/{payment}/status, then the new tier's access applies.
 */
class UpgradeSubscriptionController extends Controller
{
    public function __invoke(UpgradeSubscriptionRequest $request, SubscriptionChangeService $service): JsonResponse
    {
        $tier = SubscriptionTier::where('public_id', $request->validated('tier'))->firstOrFail();

        $method = $request->validated('payment_method')
            ? PaymentMethod::from($request->validated('payment_method'))
            : null;

        $result = $service->upgrade($request->user(), $tier, $method);

        return response()->json([
            'message' => 'Complete payment to upgrade your subscription.',
            'data' => [
                'payment' => new PaymentResource($result->payment),
                'instructions' => $result->paymentInitiation->instructions,
            ],
        ], 202);
    }
}
