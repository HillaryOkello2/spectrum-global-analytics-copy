<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A downgrade the subscriber has asked for but has not reached yet.
     *
     * Dropping to a cheaper tier mid-term would take away access they have
     * already paid for, so the new tier is parked here and applied when the
     * term rolls over — on renewal, or at expiry when the target is free. Null,
     * which is what every existing row gets, means nothing is scheduled.
     *
     * Its own migration rather than an edit to the original: the database this
     * runs against is in production and has already been migrated.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('pending_tier_id')
                ->nullable()
                ->after('tier_id')
                ->constrained('subscription_tiers');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // The constraint has to go before the column it is attached to.
            $table->dropForeign(['pending_tier_id']);
            $table->dropColumn('pending_tier_id');
        });
    }
};
