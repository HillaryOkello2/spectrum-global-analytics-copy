<?php

use App\Models\User;
use App\Services\Access\RoleService;
use Spatie\Permission\Models\Role;

function roleAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

it('creates a role with the permissions it grants', function (): void {
    $this->actingAs(roleAdmin())
        ->postJson(route('api.admin.roles.store'), [
            'name' => 'Proofreader',
            'permissions' => ['access admin portal', 'proofread products'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Proofreader')
        ->assertJsonPath('data.isSystem', false)
        ->assertJsonPath('data.usersCount', 0)
        ->assertJsonPath('data.permissions', ['access admin portal', 'proofread products']);

    expect(Role::findByName('Proofreader', RoleService::GUARD)->permissions)->toHaveCount(2);
});

it('creates a role that grants nothing yet', function (): void {
    $this->actingAs(roleAdmin())
        ->postJson(route('api.admin.roles.store'), ['name' => 'Observer', 'permissions' => []])
        ->assertCreated()
        ->assertJsonPath('data.permissions', []);
});

it('rejects a duplicate or unknown-permission role', function (): void {
    $admin = roleAdmin();

    $this->actingAs($admin)
        ->postJson(route('api.admin.roles.store'), ['name' => 'admin', 'permissions' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    $this->actingAs($admin)
        ->postJson(route('api.admin.roles.store'), ['name' => 'Ghost', 'permissions' => ['fly']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permissions.0');
});

it('replaces the permissions a role grants', function (): void {
    Role::create(['name' => 'Proofreader', 'guard_name' => RoleService::GUARD])->syncPermissions(['proofread products']);

    $this->actingAs(roleAdmin())
        ->putJson(route('api.admin.roles.permissions.update', 'Proofreader'), [
            'permissions' => ['access admin portal', 'manage topics'],
        ])
        ->assertOk()
        ->assertJsonPath('data.permissions', ['access admin portal', 'manage topics']);

    expect(Role::findByName('Proofreader', RoleService::GUARD)->hasPermissionTo('proofread products'))->toBeFalse();
});

it('strips a role back to granting nothing', function (): void {
    Role::create(['name' => 'Proofreader', 'guard_name' => RoleService::GUARD])->syncPermissions(['proofread products']);

    $this->actingAs(roleAdmin())
        ->putJson(route('api.admin.roles.permissions.update', 'Proofreader'), ['permissions' => []])
        ->assertOk()
        ->assertJsonPath('data.permissions', []);
});

it('renames a custom role', function (): void {
    Role::create(['name' => 'Proofreader', 'guard_name' => RoleService::GUARD]);

    $this->actingAs(roleAdmin())
        ->putJson(route('api.admin.roles.update', 'Proofreader'), ['name' => 'Copy Editor'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Copy Editor');

    expect(Role::where('name', 'Proofreader')->exists())->toBeFalse();
});

it('deletes an unused custom role', function (): void {
    Role::create(['name' => 'Proofreader', 'guard_name' => RoleService::GUARD]);

    $this->actingAs(roleAdmin())
        ->deleteJson(route('api.admin.roles.destroy', 'Proofreader'))
        ->assertOk();

    expect(Role::where('name', 'Proofreader')->exists())->toBeFalse();
});

it('refuses to delete a role that still has users', function (): void {
    Role::create(['name' => 'Proofreader', 'guard_name' => RoleService::GUARD]);
    $staff = User::factory()->create();
    $staff->assignRole('Proofreader');

    $this->actingAs(roleAdmin())
        ->deleteJson(route('api.admin.roles.destroy', 'Proofreader'))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'role_in_use')
        ->assertJsonPath('meta.usersCount', 1);

    expect(Role::where('name', 'Proofreader')->exists())->toBeTrue();
});

it('protects the three built-in roles from edits and deletion', function (): void {
    $admin = roleAdmin();

    foreach ([User::ADMIN, User::SYSTEM_ADMIN, User::SUBSCRIBER] as $protected) {
        $this->actingAs($admin)
            ->putJson(route('api.admin.roles.update', $protected), ['name' => 'Renamed'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'protected_role');

        $this->actingAs($admin)
            ->putJson(route('api.admin.roles.permissions.update', $protected), ['permissions' => []])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'protected_role');

        $this->actingAs($admin)
            ->deleteJson(route('api.admin.roles.destroy', $protected))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'protected_role');
    }

    expect(Role::findByName(User::ADMIN, RoleService::GUARD)->permissions)->not->toBeEmpty();
});

it('lists roles with usage counts and hides the subscriber role', function (): void {
    Role::create(['name' => 'Proofreader', 'guard_name' => RoleService::GUARD]);
    User::factory()->create()->assignRole('Proofreader');

    $response = $this->actingAs(roleAdmin())
        ->getJson(route('api.admin.roles.index'))
        ->assertOk()
        ->assertJsonMissing(['name' => User::SUBSCRIBER]);

    $roles = collect($response->json('data'))->keyBy('name');

    expect($roles->keys())->toContain('Proofreader', 'admin', 'System Admin')
        ->and($roles['Proofreader']['usersCount'])->toBe(1)
        ->and($roles['Proofreader']['isSystem'])->toBeFalse()
        ->and($roles['admin']['isSystem'])->toBeTrue();
});

it('makes a new role immediately assignable to staff', function (): void {
    Role::create(['name' => 'Proofreader', 'guard_name' => RoleService::GUARD])->syncPermissions(['access admin portal', 'proofread products']);

    $staff = User::factory()->create();
    $staff->assignRole(User::ADMIN);

    $this->actingAs(roleAdmin())
        ->putJson(route('api.admin.users.roles.update', $staff), ['roles' => ['Proofreader']])
        ->assertOk()
        ->assertJsonPath('data.roles', ['Proofreader'])
        ->assertJsonPath('data.permissions', ['access admin portal', 'proofread products']);

    // Still staff, so still reachable through the staff endpoints.
    expect($staff->fresh()->isStaff())->toBeTrue()
        ->and($staff->fresh()->portal())->toBe('admin');
});

it('confines a custom role to the endpoints its permissions allow', function (): void {
    Role::create(['name' => 'Proofreader', 'guard_name' => RoleService::GUARD])->syncPermissions(['access admin portal', 'proofread products']);

    $proofreader = User::factory()->create();
    $proofreader->assignRole('Proofreader');

    // Granted: the task board.
    $this->actingAs($proofreader)->getJson(route('api.admin.tasks.index'))->assertOk();

    // Not granted: everything else in the admin portal.
    $this->actingAs($proofreader)->getJson(route('api.admin.users.index'))->assertForbidden();
    $this->actingAs($proofreader)->getJson(route('api.admin.roles.index'))->assertForbidden();
    $this->actingAs($proofreader)->getJson(route('api.admin.subscribers.index'))->assertForbidden();
    $this->actingAs($proofreader)->getJson(route('api.admin.analytics.summary'))->assertForbidden();
    $this->actingAs($proofreader)->getJson(route('api.admin.vault.components.index'))->assertForbidden();
    $this->actingAs($proofreader)->getJson(route('api.admin.topics.index'))->assertForbidden();
});

it('locks a role without portal access out of the admin portal entirely', function (): void {
    Role::create(['name' => 'Bench', 'guard_name' => RoleService::GUARD])->syncPermissions(['proofread products']);

    $benched = User::factory()->create();
    $benched->assignRole('Bench');

    $this->actingAs($benched)->getJson(route('api.admin.tasks.index'))->assertForbidden();
});

it('still lets a System Admin reach everything without holding permissions', function (): void {
    $systemAdmin = User::factory()->create();
    $systemAdmin->assignRole(User::SYSTEM_ADMIN);

    expect($systemAdmin->getAllPermissions())->toBeEmpty();

    $this->actingAs($systemAdmin)->getJson(route('api.admin.roles.index'))->assertOk();
    $this->actingAs($systemAdmin)->getJson(route('api.admin.tasks.index'))->assertOk();
    $this->actingAs($systemAdmin)->getJson(route('api.admin.analytics.summary'))->assertOk();
});

it('forbids subscribers from managing roles', function (): void {
    $subscriber = User::factory()->create();
    $subscriber->assignRole(User::SUBSCRIBER);

    $this->actingAs($subscriber)
        ->postJson(route('api.admin.roles.store'), ['name' => 'Sneaky', 'permissions' => []])
        ->assertForbidden();

    expect(Role::where('name', 'Sneaky')->exists())->toBeFalse();
});
