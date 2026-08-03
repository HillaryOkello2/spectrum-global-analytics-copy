<?php

namespace App\Services\Publishing;

use App\Enums\ProductStatus;
use App\Enums\TaskStatus;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Takes approved products live in the order they were approved (§6, FIFO).
 *
 * Approval ends the editorial workflow; this is what makes content visible to
 * subscribers. Running it on a schedule in fixed-size batches keeps the release
 * pace steady no matter how the proofreading queue bunches up.
 */
class ReleaseService
{
    /**
     * Publish the next batch. Returns the products taken live, oldest approval
     * first.
     *
     * @return Collection<int, Product>
     */
    public function releaseBatch(?int $limit = null): Collection
    {
        $limit ??= config('publishing.release_batch');

        return DB::transaction(function () use ($limit) {
            // Locked for the duration: two overlapping scheduler runs must not
            // both claim the same head of the queue.
            $products = Product::query()
                ->awaitingRelease()
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $products->each(fn (Product $product) => $this->release($product));

            return $products;
        });
    }

    private function release(Product $product): void
    {
        $product->update([
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ]);

        // The task board mirrors the product: a released product's task is done.
        $product->generationTask?->update([
            'status' => TaskStatus::Published,
            'completed_at' => now(),
        ]);

        activity()->performedOn($product)->log('product released');
    }
}
