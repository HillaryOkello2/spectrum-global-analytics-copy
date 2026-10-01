<?php

namespace App\Http\Controllers\Api\V1\Admin\Vault;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductReviewResource;
use App\Models\Product;

/**
 * @group Admin Portal
 *
 * Vault article view: one product in full, at every content level, whatever its
 * status (FR-43, §14.11).
 *
 * GET /products/{product} cannot serve this. That route is the subscriber
 * portal's entitlement-checked reader, so a staff token is refused outright —
 * staff hold no subscription to check. Opening a product here is also not a
 * read: ReadTracker counts subscribers consuming content, not editorial review.
 */
class VaultProductController extends Controller
{
    public function show(Product $product): ProductReviewResource
    {
        return new ProductReviewResource(
            $product->load('component')->loadCount('ratings')->loadAvg('ratings', 'stars'),
        );
    }
}
