<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'access admin portal',
            'manage users',
            'manage subscribers',
            'view audit logs',
            'view analytics',
            'manage vault',
            'manage topics',
            'proofread products',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        // System Admin bypasses all policies via Gate::before / policy before().
        Role::findOrCreate('System Admin');

        Role::findOrCreate('admin')->syncPermissions($permissions);

        Role::findOrCreate('subscriber');
    }
}
