<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('components', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            // Globally unique now that Components are the top level of the
            // catalogue — routes resolve a Component by code or public_id.
            $table->string('code')->unique();
            $table->foreignId('assigned_llm_provider_id')->nullable()->constrained('llm_providers');
            $table->unsignedTinyInteger('batch');
            // Retained for pay-to-own: no seeded component sets it now that A14
            // is gone, but the purchase + gateway path stays wired so bringing a
            // transactional component back is a seeder change, not a rebuild.
            $table->boolean('is_transactional')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('components');
    }
};
