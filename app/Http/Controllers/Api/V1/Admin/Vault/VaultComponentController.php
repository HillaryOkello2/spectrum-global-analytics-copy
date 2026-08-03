<?php

namespace App\Http\Controllers\Api\V1\Admin\Vault;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComponentResource;
use App\Models\Component;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Admin Portal
 *
 * Vault drill-down level 1: Components (FR-43, §14.11).
 */
class VaultComponentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // Counts every product, matching what the vault lists — drafts and
        // hidden included, unlike the public catalogue's visible-only count.
        return ComponentResource::collection(
            Component::query()->withCount('products')->orderBy('sort_order')->get(),
        );
    }
}
