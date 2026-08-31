<?php

namespace App\Http\Resources\Concerns;

/**
 * Star-rating summary for a product payload.
 *
 * Both keys are omitted entirely unless the query loaded the aggregates
 * (`withCount('ratings')` / `withAvg('ratings', 'stars')`), so a caller can tell
 * "nobody has rated this" (count 0) apart from "ratings weren't requested"
 * (key absent).
 */
trait SummarisesRatings
{
    /**
     * @return array<string, mixed>
     */
    protected function ratingSummary(): array
    {
        return [
            // MySQL returns aggregates as strings, SQLite as numbers — cast both.
            'averageRating' => $this->when(
                $this->ratings_avg_stars !== null,
                fn () => round((float) $this->ratings_avg_stars, 2),
            ),
            'ratingsCount' => $this->when(
                $this->ratings_count !== null,
                fn () => (int) $this->ratings_count,
            ),
        ];
    }
}
