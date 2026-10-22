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
            // The token the client's own [DOCUMENT_REF] examples use. Usually the
            // same as `code`, but three differ: BS→BK, CC→CB, HM→CS. This is what
            // ProductCodeService stamps into a product code, so that the code
            // matches the reference printed inside the generated document.
            $table->string('ref_code');
            $table->foreignId('assigned_llm_provider_id')->nullable()->constrained('llm_providers');
            $table->unsignedTinyInteger('batch');
            // Retained for pay-to-own: no seeded component sets it now that A14
            // is gone, but the purchase + gateway path stays wired so bringing a
            // transactional component back is a seeder change, not a rebuild.
            $table->boolean('is_transactional')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            // --- Client prompt pack (2026-08 scope change) ---------------------
            // The client supplies one finalised prompt per component. Topics no
            // longer carry their own prompt text by default; they carry only the
            // per-run variables that get interpolated into these templates.
            $table->longText('prompt_template')->nullable();
            // Asks the LLM to invent this run's `variables` — how a recurring
            // product (daily brief, weekly highlights) gets a topic unattended.
            $table->longText('topic_prompt')->nullable();
            $table->longText('qa_prompt_template')->nullable();
            // Placeholder keys the LLM must supply, e.g. ["PRIMARY_TOPIC","BYLINE"].
            $table->json('variables')->nullable();
            // Placeholders with a constant value for this component, e.g. DB's
            // fixed [DOCUMENT_TITLE]. Merged over `variables` at render time.
            $table->json('fixed_variables')->nullable();
            // Renders products.title from the resolved variables.
            $table->string('title_template')->nullable();
            // Non-null means the scheduler generates topics for this component on
            // that cadence. Null means topics are created by an admin.
            $table->string('generation_frequency')->nullable();
            // Per-component queue, so a 56-page Research Paper cannot head-of-line
            // block the daily brief. Tunable without a deploy.
            $table->string('queue_name')->default('llm');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('components');
    }
};
