<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider answered 200 but the reply carried no text.
 *
 * Almost always means the response hit the token ceiling before the model
 * emitted anything — a reasoning model can spend the whole budget thinking and
 * return nothing but its reasoning. Without this the empty string is persisted
 * as the product body and the failure only surfaces at proofreading.
 */
class EmptyLlmResponseException extends RuntimeException
{
    /**
     * `max_tokens` from Anthropic and (lower-cased) Gemini; `length` from the
     * OpenAI-compatible APIs.
     */
    private const CEILING_REASONS = ['max_tokens', 'length'];

    private ?string $stopReason = null;

    public static function for(string $model, ?string $stopReason = null, ?int $reasoningTokens = null): self
    {
        $ceiling = in_array(strtolower((string) $stopReason), self::CEILING_REASONS, true);

        // Raising the ceiling is the wrong advice when reasoning ate it: Claude
        // Sonnet 5 given 32,000 tokens simply thought for four minutes longer.
        $because = match (true) {
            $ceiling && $reasoningTokens > 0 => " — it spent {$reasoningTokens} tokens reasoning and hit the max_tokens ceiling before writing any text. Turn the provider's reasoning off or cap it (DEEPSEEK_THINKING, MOONSHOT_THINKING, GEMINI_THINKING_BUDGET) rather than raising LLM_MAX_TOKENS.",
            $ceiling => ' — the response hit the max_tokens ceiling before any text was produced. On a reasoning model, turn its reasoning off; otherwise raise LLM_MAX_TOKENS.',
            $stopReason === null => '.',
            default => " (stop reason: {$stopReason}).",
        };

        $exception = new self("Model {$model} returned an empty response{$because}");
        $exception->stopReason = $stopReason;

        return $exception;
    }

    /**
     * The budget ran out before any text. Deterministic — the same prompt,
     * model and ceiling do it again — so retrying only pays for the same
     * wasted tokens another time. See FailOnTokenCeiling.
     */
    public function hitTokenCeiling(): bool
    {
        return in_array(strtolower((string) $this->stopReason), self::CEILING_REASONS, true);
    }
}
