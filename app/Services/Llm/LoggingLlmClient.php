<?php

namespace App\Services\Llm;

use App\Models\LlmProvider;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\LlmResult;

/**
 * Wraps a driver so every call it makes lands in llm_call_logs. Transparent to
 * callers: the decorator is what LlmManager hands out, so no caller has to
 * remember to instrument anything.
 */
readonly class LoggingLlmClient implements LlmClient
{
    public function __construct(
        private LlmClient $inner,
        private LlmCallRecorder $recorder,
        private LlmProvider $provider,
        private string $context,
        private bool $isProbe = false,
    ) {}

    public function generate(string $prompt): LlmResult
    {
        return $this->recorder->record(
            $this->provider,
            $this->context,
            $this->isProbe,
            fn (): LlmResult => $this->inner->generate($prompt),
        );
    }
}
