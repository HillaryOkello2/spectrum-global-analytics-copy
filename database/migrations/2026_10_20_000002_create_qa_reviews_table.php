<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per QA audit of a product — the first pass and every re-audit
     * after a revision.
     *
     * generation_tasks.qa_result holds only the latest report, which was
     * enough while QA was advisory. Now that a RED LIGHT sends the document
     * back to the model, the proofreader needs to see what was wrong, what was
     * asked for, and whether the model actually fixed it.
     */
    public function up(): void
    {
        Schema::create('qa_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('llm_provider_id')->nullable()->constrained('llm_providers')->nullOnDelete();
            // 0 is the audit of the first draft; 1+ audit a revision.
            $table->unsignedTinyInteger('round')->default(0);
            $table->string('verdict', 16);
            // The full audit report, as the auditor wrote it.
            $table->longText('report');
            // Parsed out of the report: ["1. STRATEGIC-ONLY MANDATE", …]
            $table->json('failed_sections')->nullable();
            // The auditor's own RE-RUN INSTRUCTIONS, which lead the revision prompt.
            $table->longText('rerun_instructions')->nullable();
            $table->timestamps();

            $table->index(['generation_task_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_reviews');
    }
};
