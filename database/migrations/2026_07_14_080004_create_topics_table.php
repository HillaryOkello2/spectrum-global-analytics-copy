<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('frequency')->index();
            // Where this topic came from: 'manual' (an admin created it) or
            // 'auto' (the scheduler had the LLM invent it from the component's
            // topic_prompt). Auto topics are the unattended daily/weekly path.
            $table->string('source')->default('manual')->index();
            // Resolved placeholder values for this run, e.g.
            // {"PRIMARY_TOPIC": "...", "BYLINE": "..."} — interpolated into the
            // component's prompt_template. Null only for legacy manual topics
            // that carry their own full prompt_text instead.
            $table->json('variables')->nullable();
            // Nullable since the 2026-08 prompt pack: when absent the component's
            // prompt_template + this topic's variables are rendered instead.
            $table->text('prompt_text')->nullable();
            $table->text('qa_prompt_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topics');
    }
};
