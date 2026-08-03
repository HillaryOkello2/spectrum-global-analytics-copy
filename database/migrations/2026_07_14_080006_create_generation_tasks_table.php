<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('llm_provider_id')->constrained('llm_providers');
            $table->string('status')->default('queued')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->text('qa_result')->nullable();
            $table->foreignId('proofreader_id')->nullable()->constrained('users');
            $table->timestamp('proofread_at')->nullable();
            // Redaction is a second, separate review pass after proofreading.
            $table->foreignId('redactor_id')->nullable()->constrained('users');
            $table->timestamp('redacted_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_tasks');
    }
};
