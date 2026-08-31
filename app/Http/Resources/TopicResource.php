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
            // 'auto' means the scheduler commissioned this edition; 'manual'
            // means an admin filed it.
            'source' => $this->source,
            // Null on an auto topic — it renders the component's template
            // against `variables` instead of carrying a prompt of its own.
            'promptText' => $this->prompt_text,
            'variables' => $this->variables,
            'qaPromptText' => $this->qa_prompt_text,
            'isActive' => $this->is_active,
            'lastGeneratedAt' => $this->last_generated_at?->format('Y-m-d H:i:s'),
            'component' => new ComponentResource($this->whenLoaded('component')),
        ];
    }
}
