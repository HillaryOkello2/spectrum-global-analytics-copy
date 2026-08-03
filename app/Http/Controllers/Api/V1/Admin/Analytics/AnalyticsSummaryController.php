<?php

namespace App\Http\Controllers\Api\V1\Admin\Analytics;

use App\Enums\ProductStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\GenerationTask;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * @group Admin Portal
 *
 * Dashboard KPI tiles (FR-42, §14.8).
 */
class AnalyticsSummaryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'totalSubscribers' => User::role('subscriber')->count(),
                'activeSubscriptions' => Subscription::active()->count(),
                'productsPublished' => Product::where('status', ProductStatus::Published)->count(),
                'pendingProofreading' => GenerationTask::whereIn('status', [
                    TaskStatus::AwaitingProofreading,
                    TaskStatus::InProofreading,
                ])->count(),
            ],
        ]);
    }
}
