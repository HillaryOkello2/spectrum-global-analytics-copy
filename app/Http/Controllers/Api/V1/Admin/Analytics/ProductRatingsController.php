<?php

namespace App\Http\Controllers\Api\V1\Admin\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnalyticsWindowRequest;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;

/**
 * @group Admin Portal
 *
 * Star ratings per product, best average first. Unrated products are omitted.
 */
class ProductRatingsController extends Controller
{
    public function __invoke(AnalyticsWindowRequest $request, AnalyticsService $analytics): JsonResponse
    {
        return response()->json([
            'data' => $analytics->productRatings($request->validated('limit')),
        ]);
    }
}
