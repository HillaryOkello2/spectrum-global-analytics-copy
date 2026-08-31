<?php

namespace App\Services\Analytics;

use App\Models\Product;
use App\Models\ProductRead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records that a subscriber actually opened a product's content, which is what
 * the "most read" chart counts.
 *
 * A locked preview is not a read — the subscriber saw an abstract and a
 * paywall. Full and redacted access both are.
 */
class ReadTracker
{
    public function record(Product $product, ?User $reader = null): void
    {
        DB::transaction(function () use ($product, $reader): void {
            ProductRead::create([
                'product_id' => $product->id,
                'user_id' => $reader?->id,
                'read_at' => now(),
            ]);

            // Denormalised so the vault and catalogue listings can show a read
            // count without joining the ledger on every row.
            $product->increment('reads_count');
        });
    }
}
