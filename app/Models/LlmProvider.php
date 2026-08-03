<?php

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LlmProvider extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'name',
        'vendor',
        'driver',
        'model_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function components(): HasMany
    {
        return $this->hasMany(Component::class, 'assigned_llm_provider_id');
    }
}
