<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'user_id',
        'subscription_id',
        'payable_type',
        'payable_id',
        'method',
        'amount',
        'currency',
        'status',
        'gateway',
        'gateway_ref',
        'idempotency_key',
        'raw_callback',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'raw_callback' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function purchase(): HasOne
    {
        return $this->hasOne(ProductPurchase::class);
    }

    /**
     * Filters for the admin transaction history (FR-42).
     *
     * `search` covers the gateway reference and the payer's name/email — the two
     * things support is given when someone asks "where did my money go".
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $q->where(function (Builder $q) use ($search) {
                    $q->where('gateway_ref', 'like', "%{$search}%")
                        ->orWhereHas('user', fn (Builder $q) => $q->where(
                            fn (Builder $q) => $q->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%"),
                        ));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['method'] ?? null, fn (Builder $q, string $method) => $q->where('method', $method))
            ->when($filters['gateway'] ?? null, fn (Builder $q, string $gateway) => $q->where('gateway', $gateway))
            ->when($filters['subscriber'] ?? null, function (Builder $q, string $publicId) {
                $q->whereHas('user', fn (Builder $q) => $q->where('public_id', $publicId));
            })
            // Filter on created_at, not paid_at: a pending or failed payment has
            // no paid_at, and those are exactly what a date-bounded audit needs
            // to surface.
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('created_at', '<=', $to));
    }
}
