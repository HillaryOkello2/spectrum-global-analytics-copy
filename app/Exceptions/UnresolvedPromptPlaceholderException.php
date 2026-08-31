<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A prompt template still had an empty slot at render time. Thrown rather than
 * silently substituting an empty string: a document that reaches a client with
 * a literal "[PRIMARY_TOPIC]" in it is worse than a failed generation task.
 */
class UnresolvedPromptPlaceholderException extends RuntimeException
{
    /**
     * @param  array<int, string>  $placeholders
     */
    public static function for(array $placeholders): self
    {
        return new self(sprintf(
            'Prompt template has unresolved placeholder(s): %s.',
            implode(', ', $placeholders)
        ));
    }
}
