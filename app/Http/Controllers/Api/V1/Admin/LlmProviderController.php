<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LlmProviderResource;
use App\Models\LlmProvider;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Admin Portal
 *
 * LLM provider directory and the permanent Component→LLM assignment map
 * (FR-20, §13.1). Each provider lists the Components whose content it
 * generates, so admins can see which model produces which component's products.
 */
class LlmProviderController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $providers = LlmProvider::query()
            ->with(['components' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('id')
            ->get();

        return LlmProviderResource::collection($providers);
    }
}
