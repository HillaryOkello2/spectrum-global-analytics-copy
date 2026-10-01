<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('payable');
            $table->string('method');
            // The number an M-Pesa prompt went to; null for card.
            $table->string('phone', 30)->nullable();
            // What the gateway was asked to charge...
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            // ...and the tier price it was converted from (see ChargeCalculator).
            $table->decimal('list_amount', 10, 2)->nullable();
            $table->string('list_currency', 3)->nullable();
            $table->decimal('exchange_rate', 12, 4)->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('failure_reason')->nullable();
            $table->string('gateway');
            $table->string('gateway_ref')->nullable()->index();
            // The gateway's own receipt, e.g. the M-Pesa transaction code.
            $table->string('transaction_code')->nullable()->index();
            $table->uuid('idempotency_key')->unique();
            $table->json('raw_callback')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
