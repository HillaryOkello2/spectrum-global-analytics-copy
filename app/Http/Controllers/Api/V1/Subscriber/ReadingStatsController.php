<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Http\Controllers\Controller;
use App\Services\Analytics\ReaderStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Subscriber Portal
 *
 * The caller's own reading and rating totals for their dashboard (FR-31).
 * Strictly about themselves — the catalogue-wide equivalents live behind
 * /admin/analytics and the `view analytics` permission.
 */
class ReadingStatsController extends Controller
{
    public function __invoke(Request $request, ReaderStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats->forUser($request->user())]);
    }
}
