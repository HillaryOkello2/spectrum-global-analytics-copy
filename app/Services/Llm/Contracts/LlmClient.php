<?php

namespace App\Services\Llm\Contracts;

use App\Services\Llm\DTOs\LlmResult;

interface LlmClient
{
    /**
     * Send a prompt to the provider and return the generated text.
     */
    public function generate(string $prompt): LlmResult;
}
