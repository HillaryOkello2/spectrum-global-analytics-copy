<?php

namespace App\Services\Llm\DTOs;

readonly class LlmResult
{
    public function __construct(
        public string $text,
        public string $model,
    ) {}
}
