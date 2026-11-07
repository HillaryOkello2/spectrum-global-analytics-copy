<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Set when a paid term lapses onto the default free tier, so the
            // portal can say "Your Superior subscription ended… You're on Freemium".
            $table->string('downgraded_from_tier_name')->nullable()->after('ends_at');
            $table->timestamp('downgraded_from_ended_at')->nullable()->after('downgraded_from_tier_name');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['downgraded_from_tier_name', 'downgraded_from_ended_at']);
        });
    }
};
