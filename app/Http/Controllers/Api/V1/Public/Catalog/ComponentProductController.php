<?php

namespace App\Http\Controllers\Api\V1\Public\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductListResource;
use App\Models\Component;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Public Catalogue
 *
 * Public catalogue: visible Products of a Component (FR-05/08).
 */
class ComponentProductController extends Controller
{
    public function index(Request $request, Component $component, AbstractPaperController $abstracts): AnonymousResourceCollection
    {
        // Abstract Papers has no products of its own: what it holds is every
        // other component's abstracts. Serving that here as well as at
        // /catalog/abstracts lets the frontend drill into any tile in the grid
        // the same way, without knowing which one is the index.
        if ($component->isVirtual()) {
            return $abstracts->index($request);
        }

        $products = $component->products()
            ->visible()
            ->with('component')
            ->withCount('ratings')
            ->withAvg('ratings', 'stars')
            ->filter($request->only(['search']))
            ->latest('published_at')
            ->paginate(20);

        return ProductListResource::collection($products);
    }
}
