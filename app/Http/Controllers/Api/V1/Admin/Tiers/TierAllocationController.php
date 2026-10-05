<?php

namespace App\Http\Controllers\Api\V1\Admin\Tiers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncTierAllocationsRequest;
use App\Http\Resources\TierResource;
use App\Models\SubscriptionTier;
use App\Services\Billing\TierService;

/**
 * @group Admin Portal
 *
 * What one tier unlocks, component by component (Annex 3). A singleton
 * sub-resource: the whole matrix is read and replaced as one value, because a
 * tier's access is only meaningful as a complete set — a component left out of
 * the submission is a component this tier does not open.
 */
class TierAllocationController extends Controller
{
    public function __construct(
        private readonly TierService $tiers,
    ) {}

    public function show(SubscriptionTier $tier): TierResource
    {
        return new TierResource($tier->load('allocations.component'));
    }

    public function update(SyncTierAllocationsRequest $request, SubscriptionTier $tier): TierResource
    {
        return new TierResource(
            $this->tiers->syncAllocations($tier, $request->validated('allocations'), $request->user()),
        );
    }
}
