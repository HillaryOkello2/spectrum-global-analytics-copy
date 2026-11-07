<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_tiers', function (Blueprint $table) {
            $table->boolean('featured')->default(false)->after('sort_order');
            $table->text('description')->nullable()->after('featured');
            $table->json('benefits')->nullable()->after('description');
            $table->boolean('is_default_free')->default(false)->after('benefits');
        });

        // Allow unpublishing price/currency. Native ALTER on MySQL/Postgres;
        // SQLite (tests) rebuilds the column via Laravel's schema grammar.
        Schema::table('subscription_tiers', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->change();
            $table->string('currency', 3)->nullable()->change();
        });

        // Existing rows keep their prices; Freemium (price 0) is the default free tier.
        DB::table('subscription_tiers')
            ->where('price', 0)
            ->orderBy('id')
            ->limit(1)
            ->update(['is_default_free' => true]);

        DB::table('subscription_tiers')
            ->where('name', 'Premium')
            ->update(['featured' => true]);
    }

    public function down(): void
    {
        DB::table('subscription_tiers')->whereNull('price')->update(['price' => 0]);
        DB::table('subscription_tiers')->whereNull('currency')->update(['currency' => 'USD']);

        Schema::table('subscription_tiers', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0)->nullable(false)->change();
            $table->string('currency', 3)->default('USD')->nullable(false)->change();
            $table->dropColumn(['featured', 'description', 'benefits', 'is_default_free']);
        });
    }
};
