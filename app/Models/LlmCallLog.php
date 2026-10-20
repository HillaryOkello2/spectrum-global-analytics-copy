<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempted call to an LLM. Written by LlmCallRecorder, read by
 * LlmHealthService — nothing else should insert rows here, or the dashboard
 * starts reporting traffic that never happened.
 */
class LlmCallLog extends Model
{
    use HasFactory, Prunable;

    public const CONTEXT_GENERATION = 'Product generation';

    public const CONTEXT_QA = 'QA';

    public const CONTEXT_REVISION = 'Revision';

    public const CONTEXT_TOPIC = 'Topic commissioning';

    public const CONTEXT_PROBE = 'Health probe';

    public const CONTEXT_MANUAL = 'Manual check';

    protected $fillable = [
        'llm_provider_id',
        'started_at',
        'finished_at',
        'success',
        'latency_ms',
        'error_code',
        'error_message',
        'context',
        'is_probe',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'success' => 'boolean',
            'latency_ms' => 'integer',
            'is_probe' => 'boolean',
        ];
    }

    /**
     * The status rules compare a provider's current latency against its own
     * 7-day average, so the retention floor is 7 days. The default keeps more
     * than that so a week-old incident is still readable on the dashboard.
     */
    public function prunable(): Builder
    {
        return static::query()->where(
            'started_at',
            '<',
            now()->subDays((int) config('llm.health.retention_days', 30)),
        );
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LlmProvider::class, 'llm_provider_id');
    }

    /** Real traffic — what requests24h / errors24h count. */
    public function scopeReal(Builder $query): Builder
    {
        return $query->where('is_probe', false);
    }

    public function scopeSince(Builder $query, \DateTimeInterface $from): Builder
    {
        return $query->where('started_at', '>=', $from);
    }
}
