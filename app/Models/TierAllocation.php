<?php

namespace App\Models;

use App\Enums\AccessType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TierAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'tier_id',
        'component_id',
        'access_type',
        'monthly_limit',
    ];

    protected function casts(): array
    {
        return [
            'access_type' => AccessType::class,
            'monthly_limit' => 'integer',
        ];
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTier::class, 'tier_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
