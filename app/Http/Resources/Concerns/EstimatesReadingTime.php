<?php

namespace App\Http\Resources\Concerns;

/**
 * Reading-time estimate for a product payload.
 *
 * Derived from the stored word count rather than the body, so listings and
 * previews — which never carry the body — can show it too.
 */
trait EstimatesReadingTime
{
    /**
     * @return array<string, mixed>
     */
    protected function readingTime(): array
    {
        return [
            'wordCount' => $this->when($this->word_count !== null, fn () => (int) $this->word_count),
            // 225 words a minute, the usual figure for dense prose, and never
            // less than a minute for a product that has any words at all.
            'readMinutes' => $this->when(
                $this->word_count !== null,
                fn () => max(1, (int) ceil($this->word_count / 225)),
            ),
        ];
    }
}
