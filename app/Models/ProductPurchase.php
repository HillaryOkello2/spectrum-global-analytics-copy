<?php

namespace App\Models;

use App\Enums\TransactionTier;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPurchase extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'user_id',
        'product_id',
        'payment_id',
        'transaction_tier',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_tier' => TransactionTier::class,
            'granted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
