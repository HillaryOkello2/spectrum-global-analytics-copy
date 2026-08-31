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

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AUTO = 'auto';

    /**
     * Mirrors the column default so a freshly created topic reports its source
     * without a round trip to the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => self::SOURCE_MANUAL,
    ];

    protected $fillable = [
        'component_id',
        'title',
        'frequency',
        'source',
        'variables',
        'prompt_text',
        'qa_prompt_text',
        'is_active',
        'last_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => Frequency::class,
            'variables' => 'array',
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

    /**
     * A topic written by hand carries its own full prompt; one the scheduler
     * created carries only variables, and renders the component's template.
     */
    public function hasOwnPrompt(): bool
    {
        return filled($this->prompt_text);
    }
}
