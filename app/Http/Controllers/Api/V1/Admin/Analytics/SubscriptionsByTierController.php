<?php

namespace App\Http\Controllers\Api\V1\Admin\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;

/**
 * @group Admin Portal
 */
class SubscriptionsByTierController extends Controller
{
    public function __invoke(AnalyticsService $analytics): JsonResponse
    {
        return response()->json(['data' => $analytics->subscriptionsByTier()]);
    }
}
