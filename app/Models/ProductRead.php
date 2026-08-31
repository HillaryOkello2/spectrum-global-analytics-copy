<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per time a subscriber actually opened a product's content.
 *
 * Distinct from ProductConsumption, which is a deduplicated *unlock* ledger
 * written only for metered tiers — it cannot answer "most read" because
 * unlimited-tier reads and every repeat read are invisible to it.
 */
class ProductRead extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'user_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
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

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $from) => $q->where('read_at', '>=', $from))
            ->when($to, fn (Builder $q, string $to) => $q->where('read_at', '<=', $to));
    }
}
