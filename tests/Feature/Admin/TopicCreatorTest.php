<?php

use App\Models\Component;
use App\Models\Topic;
use App\Models\User;
use Spatie\Permission\Models\Role;

function topicsAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

it('records who filed a topic and names them on the way back', function (): void {
    $admin = topicsAdmin();
    $component = Component::factory()->create();

    $this->actingAs($admin)
        ->postJson(route('api.admin.topics.store'), [
            'title' => 'Sovereign compute capacity',
            'component' => $component->public_id,
            'frequency' => 'monthly',
            'prompt_text' => 'Assess the structural position.',
            'qa_prompt_text' => 'Audit it.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.createdBy.publicId', $admin->public_id)
        ->assertJsonPath('data.createdBy.name', $admin->full_name);

    $this->actingAs($admin)
        ->getJson(route('api.admin.topics.index'))
        ->assertOk()
        ->assertJsonPath('data.0.createdBy.name', $admin->full_name);
});

it('names the author without the caller holding manage users', function (): void {
    // The whole point of resolving it server-side: a topics editor has no
    // business reading the user directory, and the column used to be dropped
    // rather than risk a screen that 403s for them.
    $author = topicsAdmin();
    Topic::factory()->create(['created_by' => $author->id]);

    $role = Role::create(['name' => 'Topics Editor', 'guard_name' => 'web']);
    $role->syncPermissions(['access admin portal', 'manage topics']);

    $editor = User::factory()->create();
    $editor->assignRole($role);

    $this->actingAs($editor)
        ->getJson(route('api.admin.topics.index'))
        ->assertOk()
        ->assertJsonPath('data.0.createdBy.name', $author->full_name);

    $this->actingAs($editor)
        ->getJson(route('api.admin.users.index'))
        ->assertForbidden();
});

it('leaves the author null on a topic the scheduler commissioned', function (): void {
    Topic::factory()->auto()->create();

    $this->actingAs(topicsAdmin())
        ->getJson(route('api.admin.topics.index'))
        ->assertOk()
        ->assertJsonPath('data.0.source', 'auto')
        ->assertJsonPath('data.0.createdBy', null);
});
