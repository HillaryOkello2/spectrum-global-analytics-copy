<?php

namespace App\Http\Controllers\Api\V1\Admin\Vault;

use App\Http\Controllers\Controller;
use App\Http\Resources\VaultProductListResource;
use App\Models\Component;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Admin Portal
 *
 * Vault drill-down level 3: ALL Products of a Component, including hidden and
 * unpublished (FR-43, §14.11) — admin-only visibility.
 *
 * With one exception: documents that failed quality control are not listed.
 * The QA gate exists so that model output nobody stands behind does not reach
 * the vault, and listing it here would be exactly that. It is not hidden
 * either — `?status=qa_failed` returns it, and the task board shows every
 * failure with its audit report.
 */
class VaultComponentProductController extends Controller
{
    public function index(Request $request, Component $component): AnonymousResourceCollection
    {
        $status = $request->query('status');

        $products = $component->products()
            ->with('component')
            // Default: everything the vault stands behind. An explicit status
            // filter overrides it, including `qa_failed`.
            ->when($status === null, fn ($query) => $query->inVault())
            ->when($status !== null, fn ($query) => $query->where('status', $status))
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
