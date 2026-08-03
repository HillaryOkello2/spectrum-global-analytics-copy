<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationTask extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'topic_id',
        'product_id',
        'llm_provider_id',
        'status',
        'attempts',
        'last_error',
        'qa_result',
        'proofreader_id',
        'proofread_at',
        'redactor_id',
        'redacted_at',
        'rejection_note',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'attempts' => 'integer',
            'proofread_at' => 'datetime',
            'redacted_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function llmProvider(): BelongsTo
    {
        return $this->belongsTo(LlmProvider::class);
    }

    public function proofreader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proofreader_id');
    }

    public function redactor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redactor_id');
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status));
    }
}
