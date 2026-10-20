<?php

namespace App\Models;

use App\Enums\QaVerdict;
use App\Enums\TaskStatus;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'qa_verdict',
        'qa_round',
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
            'qa_verdict' => QaVerdict::class,
            'attempts' => 'integer',
            'qa_round' => 'integer',
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

    /**
     * Every QA audit this task has had, oldest first — the first draft's, then
     * one per revision round.
     */
    public function qaReviews(): HasMany
    {
        return $this->hasMany(QaReview::class)->orderBy('round');
    }

    /**
     * The audit a rewrite has to answer: the newest one.
     *
     * `reorder()` is load-bearing. qaReviews() is ordered oldest-first for the
     * proofreader's benefit, and chaining a descending order onto it leaves
     * `round asc, round desc` — the ascending one wins, and the second rewrite
     * would be sent the first draft's audit.
     */
    public function latestQaReview(): ?QaReview
    {
        return $this->qaReviews()->reorder()->orderByDesc('round')->orderByDesc('id')->first();
    }

    /** How many rewrites are left before the document is given up on. */
    public function revisionsRemaining(): int
    {
        return max(0, (int) config('qa.max_revisions', 2) - $this->qa_round);
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
