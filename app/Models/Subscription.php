<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'user_id',
        'tier_id',
        'pending_tier_id',
        'status',
        'starts_at',
        'ends_at',
        'last_reminder_days',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTier::class, 'tier_id');
    }

    /**
     * The cheaper tier this subscription has been scheduled to drop to at the
     * end of the current term. Null unless a downgrade is pending.
     */
    public function pendingTier(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTier::class, 'pending_tier_id');
    }

    /**
     * The tier the NEXT term will run on: the scheduled downgrade if there is
     * one, otherwise the current tier. This is what a renewal is priced
     * against, so that a subscriber who has asked to drop to a cheaper tier is
     * charged the cheaper price rather than the one they are leaving.
     */
    public function nextTier(): ?SubscriptionTier
    {
        return $this->pending_tier_id !== null
            ? $this->pendingTier()->first()
            : $this->tier;
    }

    public function hasPendingDowngrade(): bool
    {
        return $this->pending_tier_id !== null;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active);
    }

    /**
     * Lapsed subscriptions that are to land on a free tier rather than simply
     * close: the subscriber asked to drop to it, so the term rolling over is
     * the moment that takes effect, not the moment access ends.
     */
    public function scopeLapsedOntoFreeTier(Builder $query): Builder
    {
        return $query->lapsed()->whereHas(
            'pendingTier',
            fn (Builder $tier) => $tier->where('price', 0),
        );
    }

    public function scopeLapsed(Builder $query): Builder
    {
        return $query
            ->where('status', SubscriptionStatus::Active)
            ->where('ends_at', '<', now());
    }
}
