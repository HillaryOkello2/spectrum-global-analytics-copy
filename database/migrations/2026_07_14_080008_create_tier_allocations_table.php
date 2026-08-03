<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tier_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tier_id')->constrained('subscription_tiers')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->string('access_type');
            $table->unsignedInteger('monthly_limit')->nullable();
            $table->timestamps();

            $table->unique(['tier_id', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tier_allocations');
    }
};
