<?php

namespace App\Models;

use App\Enums\QaVerdict;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QaReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'generation_task_id',
        'product_id',
        'llm_provider_id',
        'round',
        'verdict',
        'report',
        'failed_sections',
        'rerun_instructions',
    ];

    protected function casts(): array
    {
        return [
            'verdict' => QaVerdict::class,
            'round' => 'integer',
            'failed_sections' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(GenerationTask::class, 'generation_task_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LlmProvider::class, 'llm_provider_id');
    }
}
