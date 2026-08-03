<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Exceptions\Domain\SubscriptionNotActiveException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use Illuminate\Http\Request;

/**
 * @group Subscriber Portal
 *
 * Subscription details + active subscription card (FR-33/35).
 */
class SubscriptionController extends Controller
{
    public function show(Request $request): SubscriptionResource
    {
        $subscription = $request->user()->activeSubscription()->with('tier.allocations.component')->first()
            ?? $request->user()->subscriptions()->with('tier')->latest()->first();

        if ($subscription === null) {
            throw new SubscriptionNotActiveException('No subscription found for this account.');
        }

        return new SubscriptionResource($subscription);
    }
}
