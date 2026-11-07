<?php

namespace App\Services\Llm\DTOs;

/**
 * Credit / balance signal attached to each LLM health node.
 *
 * `source: balance_api` — vendor exposes a balance endpoint (DeepSeek, Moonshot, MiniMax).
 * `source: inferred` — no balance API; availability is read from probe / call errors
 *   (402, "insufficient balance", etc.) the way Anthropic / Gemini / OpenAI behave.
 */
readonly class LlmCredits
{
    public function __construct(
        public string $source,
        public ?bool $available,
        public ?string $balance = null,
        public ?string $currency = null,
        public ?string $checkedAt = null,
        public ?string $detail = null,
    ) {}

    /**
     * @return array{source: string, available: bool|null, balance: string|null, currency: string|null, checkedAt: string|null, detail: string|null}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'available' => $this->available,
            'balance' => $this->balance,
            'currency' => $this->currency,
            'checkedAt' => $this->checkedAt,
            'detail' => $this->detail,
        ];
    }
}
