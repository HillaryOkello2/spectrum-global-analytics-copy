<?php

namespace App\Http\Controllers\Api\V1\Public\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComponentResource;
use App\Models\Component;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Public Catalogue
 *
 * Public catalogue entry point: the nine Components (FR-04). `productsCount`
 * counts published, non-hidden products only — the vault's equivalent count
 * includes drafts and hidden products and will not match. The star rating is
 * aggregated the same way, over the ratings of visible products.
 */
class ComponentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ComponentResource::collection(
            Component::query()
                ->withCount(['products' => fn ($query) => $query->visible()])
                // One aggregate pass for the whole grid, rather than a request
                // per product to average client-side.
                ->withCount(['visibleProductRatings as ratings_count'])
                ->withAvg(['visibleProductRatings as ratings_avg_stars'], 'stars')
                ->orderBy('sort_order')
                ->get(),
        );
    }
}
