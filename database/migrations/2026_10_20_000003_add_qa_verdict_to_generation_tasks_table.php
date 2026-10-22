<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two columns the QA gate decides on.
     *
     * These were originally added by editing
     * `2026_07_14_080006_create_generation_tasks_table` in place. That file had
     * already run everywhere, so its name was already in the `migrations`
     * table and `migrate` had nothing to do — the columns appeared on fresh
     * installs and on nobody else. Production ran the QA gate's code against a
     * table without them, and every audit died on
     * `Unknown column 'qa_verdict'` after the model had already been paid to
     * write the report. That is what this repairs.
     *
     * Guarded per column: a database built from the edited migration already
     * has them, and must not fail here.
     */
    public function up(): void
    {
        Schema::table('generation_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('generation_tasks', 'qa_verdict')) {
                // Parsed from the audit report: green | red | unknown. This is
                // what decides whether the document reaches the vault.
                $table->string('qa_verdict', 16)->nullable()->after('qa_result');
            }

            if (! Schema::hasColumn('generation_tasks', 'qa_round')) {
                // 0 while the first draft is being audited, then 1 per rewrite.
                $table->unsignedTinyInteger('qa_round')->default(0)->after('qa_verdict');
            }
        });
    }

    public function down(): void
    {
        Schema::table('generation_tasks', function (Blueprint $table) {
            $table->dropColumn(['qa_verdict', 'qa_round']);
        });
    }
};
