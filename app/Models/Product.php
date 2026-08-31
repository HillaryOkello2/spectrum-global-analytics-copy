<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'component_id',
        'topic_id',
        'code',
        'title',
        'byline',
        'abstract',
        'body',
        'redacted_body',
        'redaction_approved',
        'status',
        'is_hidden',
        'approved_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'is_hidden' => 'boolean',
            'redaction_approved' => 'boolean',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'reads_count' => 'integer',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function generationTask(): HasOne
    {
        return $this->hasOne(GenerationTask::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductConsumption::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(ProductPurchase::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ProductRead::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(ProductRating::class);
    }

    /**
     * Products visible to non-admin audiences: published and not vault-hidden (§20 Content Control).
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->where('status', ProductStatus::Published)
            ->where('is_hidden', false);
    }

    /**
     * The FIFO release queue (§6): approved products still waiting to go live,
     * oldest approval first. An abstract is mandatory — it is the public
     * preview, and it is written by a human, so a product can reach `approved`
     * only once one exists.
     */
    public function scopeAwaitingRelease(Builder $query): Builder
    {
        return $query
            ->where('status', ProductStatus::Approved)
            ->whereNotNull('abstract')
            ->orderBy('approved_at');
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(
                fn (Builder $q) => $q->where('title', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"),
            ))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['component'] ?? null, function (Builder $q, string $componentPublicId) {
                $q->whereHas('component', fn (Builder $q) => $q->where('public_id', $componentPublicId));
            });
    }
}
