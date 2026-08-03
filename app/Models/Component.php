<?php

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Component extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'name',
        'code',
        'assigned_llm_provider_id',
        'batch',
        'is_transactional',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'batch' => 'integer',
            'is_transactional' => 'boolean',
        ];
    }

    /**
     * Component codes (A1–A9) are unique, so routes accept either the public_id
     * or the human-readable code.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        return static::query()
            ->where('public_id', $value)
            ->orWhere('code', $value)
            ->first();
    }

    public function assignedLlmProvider(): BelongsTo
    {
        return $this->belongsTo(LlmProvider::class, 'assigned_llm_provider_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class);
    }

    public function tierAllocations(): HasMany
    {
        return $this->hasMany(TierAllocation::class);
    }
}
