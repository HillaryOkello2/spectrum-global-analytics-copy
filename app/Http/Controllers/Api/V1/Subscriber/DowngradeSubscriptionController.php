<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriber\DowngradeSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\SubscriptionTier;
use App\Services\Billing\SubscriptionChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Subscriber Portal
 *
 * Move to a cheaper tier at the end of the current term. Nothing is charged
 * and nothing changes today: the access already paid for runs to `endsAt`, and
 * the new tier starts from the next billing.
 *
 * The companion to POST /me/subscription/upgrade — between them a tier picker
 * can offer every tier, sending the higher-priced ones to upgrade (which
 * returns payment instructions) and the lower-priced ones here (which does
 * not).
 */
class DowngradeSubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionChangeService $service,
    ) {}

    public function store(DowngradeSubscriptionRequest $request): JsonResponse
    {
        $tier = SubscriptionTier::where('public_id', $request->validated('tier'))->firstOrFail();

        $result = $this->service->downgrade($request->user(), $tier);

        // The call is idempotent in one direction: asking for the tier you are
        // already on, with a downgrade pending, cancels it instead.
        $pending = $result->subscription->pendingTier;

        return response()->json([
            'message' => $pending === null
                ? 'Scheduled downgrade cancelled. Your subscription continues on its current tier.'
                : "Downgrade to {$pending->name} scheduled for "
                    .$result->subscription->ends_at?->format('j F Y').'.',
            'data' => ['subscription' => new SubscriptionResource($result->subscription)],
        ]);
    }

    /**
     * Call off a scheduled downgrade. Succeeds whether or not one was
     * scheduled — the outcome asked for is the outcome either way.
     */
    public function destroy(Request $request): JsonResponse
    {
        $result = $this->service->cancelDowngrade($request->user());

        return response()->json([
            'message' => 'Your subscription continues on its current tier.',
            'data' => ['subscription' => new SubscriptionResource($result->subscription)],
        ]);
    }
}
