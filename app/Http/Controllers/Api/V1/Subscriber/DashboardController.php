<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Subscriber Portal
 *
 * Subscriber dashboard (§14.4): active subscription card + recent products.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $subscription = $request->user()
            ->activeSubscription()
            ->with('tier')
            ->first();

        $recentProducts = Product::query()
            ->visible()
            ->with('component')
            ->latest('published_at')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
                'recentProducts' => ProductListResource::collection($recentProducts),
            ],
        ]);
    }
}
