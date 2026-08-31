<?php

namespace App\Exceptions;

use App\Models\Component;
use RuntimeException;

/**
 * The model could not be made to produce a usable topic for a recurring
 * component. Fails the generation task so it surfaces on the queue dashboard
 * rather than quietly skipping an edition.
 */
class TopicGenerationFailedException extends RuntimeException
{
    public static function notAutomated(Component $component): self
    {
        return new self("Component {$component->code} has no prompt template to commission a topic from.");
    }

    public static function notJson(Component $component): self
    {
        return new self("Topic generation for {$component->code} did not return JSON.");
    }

    /**
     * @param  array<int, string>  $keys
     */
    public static function missingKeys(Component $component, array $keys): self
    {
        return new self(sprintf(
            'Topic generation for %s omitted required key(s): %s.',
            $component->code,
            implode(', ', $keys)
        ));
    }

    public static function nonScalar(Component $component, string $key): self
    {
        return new self("Topic generation for {$component->code} returned a non-scalar value for {$key}.");
    }
}
