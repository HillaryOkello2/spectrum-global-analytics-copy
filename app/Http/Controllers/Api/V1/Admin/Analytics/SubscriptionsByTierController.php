<?php

namespace App\Http\Controllers\Api\V1\Admin\Analytics;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionTier;
use Illuminate\Http\JsonResponse;

/**
 * @group Admin Portal
 */
class SubscriptionsByTierController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $byTier = SubscriptionTier::query()
            ->withCount(['subscriptions as active_count' => fn ($q) => $q->where('status', SubscriptionStatus::Active)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SubscriptionTier $tier) => [
                'tier' => $tier->name,
                'activeSubscriptions' => $tier->active_count,
            ]);

        return response()->json(['data' => $byTier]);
    }
}
