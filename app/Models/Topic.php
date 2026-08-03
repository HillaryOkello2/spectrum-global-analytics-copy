<?php

namespace App\Models;

use App\Enums\Frequency;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Topic extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'component_id',
        'title',
        'frequency',
        'prompt_text',
        'qa_prompt_text',
        'is_active',
        'last_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => Frequency::class,
            'is_active' => 'boolean',
            'last_generated_at' => 'datetime',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    public function generationTasks(): HasMany
    {
        return $this->hasMany(GenerationTask::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
