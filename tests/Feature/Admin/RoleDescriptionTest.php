<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

function rolesAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

it('stores the description an admin types for a role', function (): void {
    $this->actingAs(rolesAdmin())
        ->postJson(route('api.admin.roles.store'), [
            'name' => 'Proofreader',
            'description' => 'Works the task board, nothing else.',
            'permissions' => ['access admin portal', 'proofread products'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.description', 'Works the task board, nothing else.');

    $this->actingAs(rolesAdmin())
        ->getJson(route('api.admin.roles.index'))
        ->assertOk()
        ->assertJsonPath('data.0.description', 'Works the task board, nothing else.');
});

it('leaves a description alone when the update omits it, and clears it on null', function (): void {
    $role = Role::create([
        'name' => 'Proofreader',
        'description' => 'Works the task board.',
        'guard_name' => 'web',
    ]);

    // A rename must not wipe copy the renamer never saw.
    $this->actingAs(rolesAdmin())
        ->putJson(route('api.admin.roles.update', $role->name), ['name' => 'Senior Proofreader'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Senior Proofreader')
        ->assertJsonPath('data.description', 'Works the task board.');

    $this->actingAs(rolesAdmin())
        ->putJson(route('api.admin.roles.update', 'Senior Proofreader'), [
            'name' => 'Senior Proofreader',
            'description' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.description', null);
});

it('reports the built-in roles as described by nobody', function (): void {
    $this->actingAs(rolesAdmin())
        ->getJson(route('api.admin.roles.index'))
        ->assertOk()
        ->assertJsonPath('data.0.description', null);
});
