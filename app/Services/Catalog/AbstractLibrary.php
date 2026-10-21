<?php

namespace App\Services\Catalog;

use App\Models\Component;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the Abstract Papers component shows: the abstract of every published
 * product in the catalogue, whichever component wrote it.
 *
 * Abstract Papers owns no products of its own — it is the one virtual
 * component — so everything it lists is drawn from here. One place does that
 * drawing, because the listing and the catalogue tile that counts it have to
 * agree: a tile reading "63 abstracts" over a list of 61 is worse than either
 * number alone.
 *
 * Nothing here is gated. An abstract is the public preview every visitor
 * already sees (FR-09), and the full document behind it stays behind
 * GET /products/{product}, which consults EntitlementService as always. This
 * service never returns a body.
 */
class AbstractLibrary
{
    /**
     * Published, visible products that actually carry an abstract, newest
     * first.
     *
     * The abstract is mandatory before release, so in practice this excludes
     * nothing — but a product whose abstract is missing would otherwise appear
     * in the list as a title with a blank beneath it, which is the one thing
     * this component cannot show.
     */
    public function query(): Builder
    {
        return Product::query()
            ->visible()
            ->whereNotNull('abstract')
            ->where('abstract', '!=', '')
            ->with('component')
            ->withCount('ratings')
            ->withAvg('ratings', 'stars')
            ->latest('published_at');
    }

    /**
     * Fill in the counts a virtual component cannot get from its own (empty)
     * products relation, so it sits in the catalogue grid alongside the nine
     * with figures that mean the same thing.
     *
     * @param  Collection<int, Component>  $components
     */
    public function summarise(Collection $components): Collection
    {
        $virtual = $components->filter(fn (Component $component) => $component->isVirtual());

        if ($virtual->isEmpty()) {
            return $components;
        }

        // toBase(): the eager loads and rating sub-selects of the listing
        // query are needless work for a count.
        $products = $this->query()->toBase()->getCountForPagination();

        // The star rating across everything it lists — the same ratings the
        // other tiles show, pooled.
        $ratings = $this->ratingSummary();

        foreach ($virtual as $component) {
            $component->setAttribute('products_count', $products);
            $component->setAttribute('ratings_count', (int) $ratings->count);
            $component->setAttribute('ratings_avg_stars', $ratings->average);
        }

        return $components;
    }

    private function ratingSummary(): object
    {
        $row = Product::query()
            ->visible()
            ->whereNotNull('abstract')
            ->join('product_ratings', 'product_ratings.product_id', '=', 'products.id')
            ->selectRaw('count(*) as count, avg(product_ratings.stars) as average')
            ->first();

        return (object) [
            'count' => $row?->count ?? 0,
            'average' => $row?->average,
        ];
    }
}
