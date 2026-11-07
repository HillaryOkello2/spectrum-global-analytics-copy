<?php

namespace App\Http\Controllers\Api\V1\Admin\Vault;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateVaultComponentRequest;
use App\Http\Resources\ComponentResource;
use App\Models\Component;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
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
        // Counts what the vault lists — drafts and hidden included, unlike the
        // public catalogue's visible-only count, but excluding documents that
        // failed quality control, so the count matches the drill-down.
        // The rating average follows suit and spans them all.
        return ComponentResource::collection(
            Component::query()
                // The vault is the editorial record of what has been produced.
                // Abstract Papers produces nothing and owns no products — it
                // re-presents other components' published abstracts — so it
                // would sit here as a permanent empty row.
                ->generating()
                ->withCount(['products' => fn (Builder $query) => $query->inVault()])
                ->withCount(['productRatings as ratings_count'])
                ->withAvg(['productRatings as ratings_avg_stars'], 'stars')
                ->orderBy('sort_order')
                ->get(),
        );
    }

    public function update(UpdateVaultComponentRequest $request, Component $component): ComponentResource
    {
        $before = $component->only(array_keys($request->validated()));

        $component->update($request->validated());

        Audit::of($component)->by($request->user())
            ->changes($before, $request->validated())
            ->log('vault component updated');

        return new ComponentResource($component->refresh());
    }
}
