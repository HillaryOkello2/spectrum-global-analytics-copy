<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriber\StoreProductRatingRequest;
use App\Http\Resources\ProductRatingResource;
use App\Models\Product;
use App\Services\Catalog\ProductRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @group Subscriber Portal
 *
 * A subscriber's own star rating of one product (1–5). Rating requires the same
 * access that reading does — an abstract-only preview is not enough — so a
 * subscriber whose tier withholds the product gets a 403.
 */
class ProductRatingController extends Controller
{
    public function __construct(
        private readonly ProductRatingService $ratings,
    ) {}

    public function show(Request $request, Product $product): JsonResponse
    {
        $rating = $this->ratings->ratingFor($request->user(), $product);

        // Not having rated yet is a normal state, not a 404.
        return response()->json([
            'data' => $rating === null ? null : (new ProductRatingResource($rating))->resolve($request),
        ]);
    }

    public function update(StoreProductRatingRequest $request, Product $product): ProductRatingResource
    {
        abort_unless(
            $this->ratings->canRate($request->user(), $product),
            403,
            'You need access to this product before you can rate it.',
        );

        return new ProductRatingResource(
            $this->ratings->rate($request->user(), $product, $request->integer('stars')),
        );
    }

    public function destroy(Request $request, Product $product): Response
    {
        $this->ratings->withdraw($request->user(), $product);

        return response()->noContent();
    }
}
