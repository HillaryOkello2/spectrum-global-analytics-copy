<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A component that generates nothing of its own and instead presents other
     * components' work — Abstract Papers, which is the whole catalogue's
     * abstracts in one place. Nothing in the generation pipeline, the vault or
     * the tier access matrix ever targets one of these.
     *
     * Its own migration rather than an edit to the original: the database this
     * runs against is in production and has already been migrated.
     */
    public function up(): void
    {
        Schema::table('components', function (Blueprint $table) {
            $table->boolean('is_virtual')->default(false)->after('is_transactional');
        });
    }

    public function down(): void
    {
        Schema::table('components', function (Blueprint $table) {
            $table->dropColumn('is_virtual');
        });
    }
};
