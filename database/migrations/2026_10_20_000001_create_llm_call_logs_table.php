<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per LLM request *attempt* — retries included, because a vendor
     * that only answers on the second try is not healthy and the dashboard has
     * no other way to see it.
     *
     * This is the only source of the numbers behind
     * `GET /admin/analytics/llm-health`; nothing else in the system records
     * that a model was called at all.
     */
    public function up(): void
    {
        Schema::create('llm_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('llm_provider_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->boolean('success');
            // Successful calls only; a failure has no meaningful latency to
            // average, and mixing the two makes a timeout look like slowness.
            $table->unsignedInteger('latency_ms')->nullable();
            // HTTP status as a string ("429") or a slug: timeout, network,
            // invalid_response, content_filtered, unknown.
            $table->string('error_code', 40)->nullable();
            // Sanitised and truncated before it is written — vendor errors echo
            // the API key often enough that masking at read time is too late.
            $table->string('error_message', 300)->nullable();
            $table->string('context', 60)->nullable();
            // Probes are excluded from requests24h/errors24h: they would drown
            // out real traffic on a quiet provider and make the error rate
            // meaningless.
            $table->boolean('is_probe')->default(false);
            $table->timestamps();

            // The health endpoint's every query is "this provider, this window".
            $table->index(['llm_provider_id', 'started_at']);
            // Serves the two filtered passes (real calls only, probes only)
            // without a scan of a provider's whole history.
            $table->index(['llm_provider_id', 'is_probe', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_call_logs');
    }
};
