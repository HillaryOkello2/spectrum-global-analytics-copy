<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Local/staging bootstrap admin. Change or remove for production.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@spectrumglobalanalytics.test'],
            [
                'first_name' => 'System',
                'last_name' => 'Admin',
                'phone' => '+254700000000',
                'country' => 'Kenya',
                'password' => 'password',
                'status' => UserStatus::Active,
            ],
        );

        $admin->syncRoles(['System Admin', 'admin']);
    }
}
