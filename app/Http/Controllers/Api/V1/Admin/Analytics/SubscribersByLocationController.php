<?php

namespace App\Http\Controllers\Api\V1\Admin\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;

/**
 * @group Admin Portal
 *
 * Subscribers grouped by registered country, for the location chart.
 */
class SubscribersByLocationController extends Controller
{
    public function __invoke(AnalyticsService $analytics): JsonResponse
    {
        return response()->json(['data' => $analytics->subscribersByLocation()]);
    }
}
