<?php

namespace App\Http\Controllers\Api\V1\Admin\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\LlmHealthService;
use Illuminate\Http\JsonResponse;

/**
 * @group Admin Portal
 *
 * LLM health panel: one node per provider, its 24-hour traffic, and the
 * failures behind it (§13.1).
 *
 * Everything here is measured, not declared — LlmCallRecorder writes a row per
 * attempt and `llm:probe` keeps quiet providers honest. Statuses are decided
 * server-side; the status rules are in docs/API_REFERENCE.md.
 */
class LlmHealthController extends Controller
{
    public function __invoke(LlmHealthService $health): JsonResponse
    {
        return response()->json(['data' => $health->snapshot()]);
    }
}
