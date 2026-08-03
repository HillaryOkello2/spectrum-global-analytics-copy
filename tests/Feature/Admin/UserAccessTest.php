<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;

function actorWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('lists assignable roles with the permissions each one grants', function (): void {
    $this->actingAs(actorWithRole('admin'))
        ->getJson(route('api.admin.roles.index'))
        ->assertOk()
        ->assertJsonStructure(['data' => [['name', 'permissions']]])
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['name' => 'admin'])
        ->assertJsonFragment(['name' => 'System Admin'])
        // Subscriber accounts come from registration, so the role is not on
        // offer in the staff role picker.
        ->assertJsonMissing(['name' => 'subscriber'])
        // The admin role carries the portal permissions; System Admin bypasses
        // gates instead of holding them.
        ->assertJsonFragment(['name' => 'admin', 'permissions' => Permission::orderBy('id')->pluck('name')->all()]);
});

it('lists every permission in the system', function (): void {
    $this->actingAs(actorWithRole('admin'))
        ->getJson(route('api.admin.permissions.index'))
        ->assertOk()
        ->assertJsonCount(Permission::count(), 'data')
        ->assertJsonFragment(['name' => 'manage users']);
});

it('replaces a users roles and reports effective permissions', function (): void {
    $staff = actorWithRole('admin');

    $this->actingAs(actorWithRole('System Admin'))
        ->putJson(route('api.admin.users.roles.update', $staff), ['roles' => ['System Admin']])
        ->assertOk()
        ->assertJsonPath('data.roles', ['System Admin'])
        // effectivePermissions() orders by name for a System Admin.
        ->assertJsonFragment(['permissions' => Permission::orderBy('name')->pluck('name')->all()]);

    expect($staff->fresh()->hasRole('System Admin'))->toBeTrue()
        ->and($staff->fresh()->hasRole('admin'))->toBeFalse();
});

it('grants direct permissions on top of a users roles', function (): void {
    $staff = actorWithRole('admin');

    $this->actingAs(actorWithRole('admin'))
        ->putJson(route('api.admin.users.permissions.update', $staff), [
            'permissions' => ['proofread products'],
        ])
        ->assertOk()
        ->assertJsonPath('data.directPermissions', ['proofread products']);

    expect($staff->fresh()->hasPermissionTo('proofread products'))->toBeTrue();
});

it('clears direct permissions when given an empty array', function (): void {
    $staff = actorWithRole('admin');
    $staff->givePermissionTo('proofread products');

    $this->actingAs(actorWithRole('admin'))
        ->putJson(route('api.admin.users.permissions.update', $staff), ['permissions' => []])
        ->assertOk()
        ->assertJsonPath('data.directPermissions', []);

    expect($staff->fresh()->getDirectPermissions())->toBeEmpty();
});

it('reports the full permission set for a System Admin, which bypasses gates', function (): void {
    $systemAdmin = actorWithRole('System Admin');

    // The role itself carries no permission rows...
    expect($systemAdmin->getAllPermissions())->toBeEmpty();

    // ...but the API must not understate what it can do, or the frontend would
    // hide the admin UI from the most privileged account.
    $this->actingAs($systemAdmin)
        ->getJson(route('api.auth.me'))
        ->assertOk()
        ->assertJsonPath('data.user.permissions', Permission::orderBy('name')->pluck('name')->all())
        ->assertJsonPath('data.portal', 'admin');
});

it('blocks an admin from changing their own roles', function (): void {
    $admin = actorWithRole('admin');

    $this->actingAs($admin)
        ->putJson(route('api.admin.users.roles.update', $admin), ['roles' => ['System Admin']])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'self_access_change');

    expect($admin->fresh()->hasRole('System Admin'))->toBeFalse();
});

it('blocks a plain admin from granting the System Admin role', function (): void {
    $staff = actorWithRole('admin');

    $this->actingAs(actorWithRole('admin'))
        ->putJson(route('api.admin.users.roles.update', $staff), ['roles' => ['System Admin']])
        ->assertForbidden()
        ->assertJsonPath('code', 'role_escalation');

    expect($staff->fresh()->hasRole('System Admin'))->toBeFalse();
});

it('blocks a plain admin from demoting an existing System Admin', function (): void {
    $systemAdmin = actorWithRole('System Admin');

    $this->actingAs(actorWithRole('admin'))
        ->putJson(route('api.admin.users.roles.update', $systemAdmin), ['roles' => ['admin']])
        ->assertForbidden()
        ->assertJsonPath('code', 'role_escalation');

    expect($systemAdmin->fresh()->hasRole('System Admin'))->toBeTrue();
});

it('lets a System Admin grant the System Admin role', function (): void {
    $staff = actorWithRole('admin');

    $this->actingAs(actorWithRole('System Admin'))
        ->putJson(route('api.admin.users.roles.update', $staff), ['roles' => ['System Admin']])
        ->assertOk()
        ->assertJsonPath('data.roles', ['System Admin']);
});

it('refuses to demote the last System Admin', function (): void {
    $lastSystemAdmin = actorWithRole('System Admin');
    $other = actorWithRole('System Admin');

    // Two exist, so demoting one is allowed.
    $this->actingAs($lastSystemAdmin)
        ->putJson(route('api.admin.users.roles.update', $other), ['roles' => ['admin']])
        ->assertOk();

    // Now only one remains — a second System Admin does the demoting so the
    // self-change guard doesn't mask the result.
    $demoter = actorWithRole('System Admin');

    $this->actingAs($demoter)
        ->putJson(route('api.admin.users.roles.update', $lastSystemAdmin), ['roles' => ['admin']])
        ->assertOk();

    $this->actingAs($other->fresh())
        ->putJson(route('api.admin.users.roles.update', $demoter), ['roles' => ['admin']])
        ->assertForbidden();
});

it('rejects unknown roles and permissions', function (): void {
    $staff = actorWithRole('admin');
    $admin = actorWithRole('admin');

    $this->actingAs($admin)
        ->putJson(route('api.admin.users.roles.update', $staff), ['roles' => ['wizard']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('roles.0');

    $this->actingAs($admin)
        ->putJson(route('api.admin.users.permissions.update', $staff), ['permissions' => ['fly']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permissions.0');
});

it('forbids subscribers from the access-control endpoints', function (): void {
    $subscriber = actorWithRole('subscriber');

    $this->actingAs($subscriber)->getJson(route('api.admin.roles.index'))->assertForbidden();
    $this->actingAs($subscriber)->getJson(route('api.admin.permissions.index'))->assertForbidden();
    $this->actingAs($subscriber)
        ->putJson(route('api.admin.users.roles.update', actorWithRole('admin')), ['roles' => ['admin']])
        ->assertForbidden();
});
