<?php

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductRating;
use App\Models\User;
use App\Services\Entitlement\EntitlementService;

/**
 * Star ratings on a published product.
 *
 * Rating is gated on the same entitlement check that gates reading: a
 * subscriber who only ever saw the locked abstract has not read the product and
 * cannot rate it. Without that, ratings would be open to every Freemium account
 * on every product in the catalogue.
 */
class ProductRatingService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Whether this subscriber has enough access to the product to rate it.
     */
    public function canRate(User $user, Product $product): bool
    {
        if ($product->status !== ProductStatus::Published || $product->is_hidden) {
            return false;
        }

        $result = $this->entitlements->check($user, $product->loadMissing('component'));

        return $result->grantsFullAccess() || $result->grantsRedactedAccess();
    }

    /**
     * Record or revise this subscriber's rating. One row per subscriber per
     * product, so re-rating replaces rather than stacks.
     */
    public function rate(User $user, Product $product, int $stars): ProductRating
    {
        return $user->ratings()->updateOrCreate(
            ['product_id' => $product->id],
            ['stars' => $stars],
        );
    }

    public function withdraw(User $user, Product $product): void
    {
        $user->ratings()->where('product_id', $product->id)->delete();
    }

    public function ratingFor(User $user, Product $product): ?ProductRating
    {
        return $user->ratings()->where('product_id', $product->id)->first();
    }
}
