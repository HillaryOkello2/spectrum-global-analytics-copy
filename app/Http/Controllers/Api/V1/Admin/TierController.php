<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTierRequest;
use App\Http\Resources\TierResource;
use App\Models\SubscriptionTier;
use App\Services\Billing\TierService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Admin Portal
 *
 * Subscription tiers: what each one costs and whether it can still be bought.
 *
 * Unlike the public `GET /tiers`, this lists inactive tiers too — an admin has
 * to be able to see the one they withdrew in order to bring it back.
 */
class TierController extends Controller
{
    public function __construct(
        private readonly TierService $tiers,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return TierResource::collection(
            SubscriptionTier::query()
                ->with('allocations.component')
                ->withCount('subscriptions')
                ->orderBy('sort_order')
                ->get(),
        );
    }

    public function show(SubscriptionTier $tier): TierResource
    {
        return new TierResource(
            $tier->load('allocations.component')->loadCount('subscriptions'),
        );
    }

    public function update(UpdateTierRequest $request, SubscriptionTier $tier): TierResource
    {
        return new TierResource(
            $this->tiers->update($tier, $request->validated(), $request->user())
                ->loadCount('subscriptions'),
        );
    }
}
