<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LlmProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'vendor' => $this->vendor,
            'modelId' => $this->model_id,
            'isActive' => $this->is_active,
            'components' => ComponentResource::collection($this->whenLoaded('components')),
        ];
    }
}
