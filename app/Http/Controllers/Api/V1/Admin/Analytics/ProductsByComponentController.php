<?php

namespace App\Http\Controllers\Api\V1\Admin\Analytics;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Component;
use Illuminate\Http\JsonResponse;

/**
 * @group Admin Portal
 */
class ProductsByComponentController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $byComponent = Component::query()
            ->withCount(['products as products_published_count' => fn ($q) => $q->where('status', ProductStatus::Published)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Component $component) => [
                'component' => $component->name,
                'code' => $component->code,
                // Deliberately counts published products including vault-hidden
                // ones: hiding is a subscriber-facing control, not a measure of
                // editorial output. This will not match the catalogue's
                // `productsCount`, which is visible-only.
                'productsPublished' => (int) $component->products_published_count,
            ]);

        return response()->json(['data' => $byComponent]);
    }
}
