<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider answered 200 but the reply carried no text.
 *
 * Almost always means the response hit the token ceiling before the model
 * emitted anything — a reasoning model can spend the whole budget thinking and
 * return nothing but a `thinking` block. Without this the empty string is
 * persisted as the product body and the failure only surfaces at proofreading.
 */
class EmptyLlmResponseException extends RuntimeException
{
    public static function for(string $model, ?string $stopReason = null): self
    {
        $because = match ($stopReason) {
            'max_tokens', 'length' => ' — the response hit the max_tokens ceiling before any text was produced; raise LLM_MAX_TOKENS.',
            null => '.',
            default => " (stop reason: {$stopReason}).",
        };

        return new self("Model {$model} returned an empty response{$because}");
    }
}
