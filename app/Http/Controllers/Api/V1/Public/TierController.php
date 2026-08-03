<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\TierResource;
use App\Models\SubscriptionTier;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Public Catalogue
 *
 * Tier cards for the subscription page (§14.7, Annex 3).
 */
class TierController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $tiers = SubscriptionTier::query()
            ->active()
            ->with('allocations.component')
            ->orderBy('sort_order')
            ->get();

        return TierResource::collection($tiers);
    }
}
