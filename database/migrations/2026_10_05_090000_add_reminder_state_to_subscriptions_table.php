<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which expiry notice a subscription has already had (7 days out, then 1),
     * so a daily reminder run never repeats itself. Cleared whenever the term
     * is extended.
     *
     * Added as its own migration rather than edited into the original: the
     * database has already been migrated fresh with real data since.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedTinyInteger('last_reminder_days')->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('last_reminder_days');
        });
    }
};
