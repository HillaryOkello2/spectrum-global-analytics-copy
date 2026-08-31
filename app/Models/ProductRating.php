<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subscriber's star rating of a product. One per subscriber per product —
 * re-rating updates the existing row rather than stacking.
 */
class ProductRating extends Model
{
    use HasFactory;

    public const MIN_STARS = 1;

    public const MAX_STARS = 5;

    protected $fillable = [
        'product_id',
        'user_id',
        'stars',
    ];

    protected function casts(): array
    {
        return [
            'stars' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
