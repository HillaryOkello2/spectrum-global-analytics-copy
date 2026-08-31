<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductRatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stars' => $this->stars,
            'ratedAt' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
