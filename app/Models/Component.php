<?php

namespace App\Models;

use App\Enums\Frequency;
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
        'ref_code',
        'assigned_llm_provider_id',
        'batch',
        'is_transactional',
        'sort_order',
        'prompt_template',
        'topic_prompt',
        'qa_prompt_template',
        'variables',
        'fixed_variables',
        'title_template',
        'generation_frequency',
        'queue_name',
    ];

    protected function casts(): array
    {
        return [
            'batch' => 'integer',
            'is_transactional' => 'boolean',
            'variables' => 'array',
            'fixed_variables' => 'array',
            'generation_frequency' => Frequency::class,
        ];
    }

    /**
     * Whether a topic can be commissioned from the model for this component at
     * all. True for every component in the client's prompt pack — an admin can
     * ask for a suggested topic on demand.
     */
    public function canCommissionTopics(): bool
    {
        return filled($this->topic_prompt) && filled($this->prompt_template);
    }

    /**
     * Whether the *scheduler* commissions topics unattended. Only the recurring
     * pulse products; the long-form components are commissioned by hand.
     */
    public function generatesOwnTopics(): bool
    {
        return $this->generation_frequency !== null && $this->canCommissionTopics();
    }

    /**
     * Component codes are unique, so routes accept either the public_id or the
     * human-readable code (e.g. `DB`).
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

    /**
     * Topics the scheduler created for this component, newest first — the basis
     * for deciding whether the next edition is due.
     */
    public function autoTopics(): HasMany
    {
        return $this->hasMany(Topic::class)->where('source', Topic::SOURCE_AUTO);
    }
}
