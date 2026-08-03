<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            // Human-readable reference, e.g. SGA.A4.2026-08.017 — see ProductCodeService.
            $table->string('code')->unique();
            $table->string('title');
            // Written by a proofreader, never by the LLM. This is the public
            // preview, so a product cannot be released without one.
            $table->text('abstract')->nullable();
            $table->longText('body');
            // Proofread and approved as its own step, after the document.
            $table->longText('redacted_body')->nullable();
            $table->boolean('redaction_approved')->default(false);
            $table->string('status')->default('draft');
            $table->boolean('is_hidden')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['component_id', 'status', 'is_hidden', 'published_at']);
            // Drives the FIFO release queue: oldest approval first.
            $table->index(['status', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
