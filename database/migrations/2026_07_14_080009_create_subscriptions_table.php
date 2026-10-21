<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tier_id')->constrained('subscription_tiers');
            // A downgrade the subscriber has asked for but has not reached yet.
            // Dropping to a cheaper tier mid-term would take away access they
            // have already paid for, so the new tier is parked here and applied
            // when the term rolls over — on renewal, or at expiry when the
            // target is free. Null means nothing is scheduled.
            $table->foreignId('pending_tier_id')->nullable()->constrained('subscription_tiers');
            $table->string('status')->default('pending')->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
