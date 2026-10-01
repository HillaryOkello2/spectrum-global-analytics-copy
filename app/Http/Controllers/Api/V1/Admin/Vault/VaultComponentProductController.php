<?php

namespace App\Http\Controllers\Api\V1\Admin\Vault;

use App\Http\Controllers\Controller;
use App\Http\Resources\VaultProductListResource;
use App\Models\Component;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Admin Portal
 *
 * Vault drill-down level 3: ALL Products of a Component, including hidden and
 * unpublished (FR-43, §14.11) — admin-only visibility.
 */
class VaultComponentProductController extends Controller
{
    public function index(Component $component): AnonymousResourceCollection
    {
        $products = $component->products()
            ->with('component')
            // Aggregated per page, so a vault table of hundreds of rows can
            // show ratings without a request per row.
            ->withCount('ratings')
            ->withAvg('ratings', 'stars')
            ->latest()
            ->paginate(20);

        // Built before the resource collection: ::collection() swaps the
        // paginator's models for resource instances in place.
        $statuses = $products->getCollection()->mapWithKeys(fn (Product $product) => [
            $product->public_id => [
                'status' => $product->status->value,
                'isHidden' => $product->is_hidden,
            ],
        ]);

        // Each row now carries its own status, hidden flag and read count;
        // meta.statuses is kept for clients written against the older shape.
        return VaultProductListResource::collection($products)
            ->additional(['meta' => ['statuses' => $statuses]]);
    }
}
