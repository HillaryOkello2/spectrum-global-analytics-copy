<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TopicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'title' => $this->title,
            'frequency' => $this->frequency->value,
            'promptText' => $this->prompt_text,
            'qaPromptText' => $this->qa_prompt_text,
            'isActive' => $this->is_active,
            'lastGeneratedAt' => $this->last_generated_at?->format('Y-m-d H:i:s'),
            'component' => new ComponentResource($this->whenLoaded('component')),
        ];
    }
}
