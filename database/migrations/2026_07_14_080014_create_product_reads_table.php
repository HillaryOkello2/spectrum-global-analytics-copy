<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-read ledger behind the "most read" chart.
     *
     * product_consumptions looks like it could answer this but cannot: it is a
     * deduplicated *unlock* ledger written only for AccessType::Metered, so
     * unlimited-tier reads and every repeat read are invisible to it.
     */
    public function up(): void
    {
        Schema::create('product_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Nullable so a read survives the reader's account being deleted —
            // the chart is about the product, not the person.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            // Drives "top products between two dates".
            $table->index(['product_id', 'read_at']);
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reads');
    }
};
