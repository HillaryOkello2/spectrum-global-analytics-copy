<?php

namespace App\Http\Controllers\Api\V1\Admin\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;

/**
 * @group Admin Portal
 *
 * Dashboard KPI tiles (FR-42, §14.8).
 */
class AnalyticsSummaryController extends Controller
{
    public function __invoke(AnalyticsService $analytics): JsonResponse
    {
        return response()->json(['data' => $analytics->summary()]);
    }
}
