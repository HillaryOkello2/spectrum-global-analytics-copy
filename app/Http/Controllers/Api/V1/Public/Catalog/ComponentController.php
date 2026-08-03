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
 * includes drafts and hidden products and will not match.
 */
class ComponentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ComponentResource::collection(
            Component::query()
                ->withCount(['products' => fn ($query) => $query->visible()])
                ->orderBy('sort_order')
                ->get(),
        );
    }
}
