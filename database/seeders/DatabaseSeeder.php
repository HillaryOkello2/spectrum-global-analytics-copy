<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Note: no WithoutModelEvents here — HasPublicId assigns UUIDs in a `creating`
     * model event, which must fire during seeding.
     */
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            LlmProviderSeeder::class,
            ComponentSeeder::class,
            SubscriptionTierSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
