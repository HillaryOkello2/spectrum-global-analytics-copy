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
    public function index(Request $request, Component $component): AnonymousResourceCollection
    {
        $products = $component->products()
            ->visible()
            ->with('component')
            ->filter($request->only(['search']))
            ->latest('published_at')
            ->paginate(20);

        return ProductListResource::collection($products);
    }
}
