<?php

namespace App\Http\Controllers\Api\V1\Public\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComponentResource;
use App\Models\Component;
use App\Services\Catalog\AbstractLibrary;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Public Catalogue
 *
 * Public catalogue entry point: the nine Components that produce documents,
 * plus Abstract Papers, which produces none and instead indexes the abstracts
 * of all of them (FR-04).
 *
 * `productsCount` counts published, non-hidden products only — the vault's
 * equivalent count includes drafts and hidden products and will not match. The
 * star rating is aggregated the same way, over the ratings of visible
 * products. For Abstract Papers both figures span the whole catalogue, since
 * that is what it lists.
 */
class ComponentController extends Controller
{
    public function __construct(
        private readonly AbstractLibrary $abstracts,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $components = Component::query()
            ->withCount(['products' => fn ($query) => $query->visible()])
            // One aggregate pass for the whole grid, rather than a request
            // per product to average client-side.
            ->withCount(['visibleProductRatings as ratings_count'])
            ->withAvg(['visibleProductRatings as ratings_avg_stars'], 'stars')
            ->orderBy('sort_order')
            ->get();

        return ComponentResource::collection(
            // A virtual component owns no products, so the counts above come
            // back as zero for it. This fills in what it actually lists.
            $this->abstracts->summarise($components),
        );
    }
}
