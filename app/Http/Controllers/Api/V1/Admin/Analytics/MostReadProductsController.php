<?php

namespace App\Http\Controllers\Api\V1\Admin\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnalyticsWindowRequest;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;

/**
 * @group Admin Portal
 *
 * Most-read products. Omit `from`/`to` for the all-time ranking; supply either
 * to rank within that window instead.
 */
class MostReadProductsController extends Controller
{
    public function __invoke(AnalyticsWindowRequest $request, AnalyticsService $analytics): JsonResponse
    {
        return response()->json([
            'data' => $analytics->mostReadProducts(
                $request->validated('from'),
                $request->validated('to'),
                $request->validated('limit'),
            ),
        ]);
    }
}
