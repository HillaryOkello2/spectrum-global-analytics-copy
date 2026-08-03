<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->timestamp('consumed_at');
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
            $table->index(['user_id', 'component_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_consumptions');
    }
};
