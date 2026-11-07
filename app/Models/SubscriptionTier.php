<?php

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionTier extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'name',
        'price',
        'currency',
        'billing_period',
        'is_active',
        'sort_order',
        'featured',
        'description',
        'benefits',
        'is_default_free',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'featured' => 'boolean',
            'benefits' => 'array',
            'is_default_free' => 'boolean',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TierAllocation::class, 'tier_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'tier_id');
    }

    /**
     * Free for billing purposes: price is published and zero. An unpriced tier
     * (null price) is not free — it cannot be purchased or joined.
     */
    public function isFree(): bool
    {
        return $this->isPriced() && (float) $this->price === 0.0;
    }

    /**
     * Has a published list price and currency, so it can appear on the public
     * pricing page and be used for signup / upgrade / downgrade / renew.
     */
    public function isPriced(): bool
    {
        return $this->price !== null && filled($this->currency);
    }

    public function isDefaultFree(): bool
    {
        return (bool) $this->is_default_free;
    }

    /**
     * The tier new accounts join for free and that lapsed paid subscribers land
     * on. Exactly one row should be flagged; falls back to the first free
     * active tier so a mis-seeded database still has somewhere to put people.
     */
    public static function defaultFree(): ?self
    {
        return static::query()->where('is_default_free', true)->first()
            ?? static::query()->active()->where('price', 0)->orderBy('sort_order')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePriced(Builder $query): Builder
    {
        return $query->whereNotNull('price')->whereNotNull('currency')->where('currency', '!=', '');
    }
}
