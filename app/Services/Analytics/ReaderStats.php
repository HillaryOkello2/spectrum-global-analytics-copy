<?php

namespace App\Services\Analytics;

use App\Models\ProductRating;
use App\Models\ProductRead;
use App\Models\User;

/**
 * A subscriber's own reading and rating totals (FR-31), from the same two
 * ledgers the admin charts read — product_reads and product_ratings.
 */
class ReaderStats
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $ratings = ProductRating::query()->where('user_id', $user->id);

        $average = (clone $ratings)->avg('stars');

        return [
            // Distinct products: re-reading one article is not two articles read.
            'articlesRead' => ProductRead::query()
                ->where('user_id', $user->id)
                ->distinct()
                ->count('product_id'),
            // Every open, including repeats — the two differ and both are asked for.
            'reads' => ProductRead::query()->where('user_id', $user->id)->count(),
            'ratingsGiven' => (clone $ratings)->count(),
            'averageRatingGiven' => $average === null ? null : round((float) $average, 2),
        ];
    }
}
