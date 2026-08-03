<?php

use App\Models\Subscription;
use App\Models\User;

function staffAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

function staffPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Grace',
        'last_name' => 'Wanjiru',
        'phone' => '+254733112233',
        'country' => 'Kenya',
        'email' => 'grace@example.com',
        'password' => 'Str0ngPassword!',
        'roles' => ['admin'],
        ...$overrides,
    ];
}

it('hides subscriber accounts from every staff endpoint', function (): void {
    $subscriber = User::factory()->create();
    $subscriber->assignRole(User::SUBSCRIBER);

    $admin = staffAdmin();

    $this->actingAs($admin)->getJson(route('api.admin.users.show', $subscriber))->assertNotFound();
    $this->actingAs($admin)->patchJson(route('api.admin.users.update', $subscriber), ['country' => 'Uganda'])->assertNotFound();
    $this->actingAs($admin)->getJson(route('api.admin.users.roles.show', $subscriber))->assertNotFound();
    $this->actingAs($admin)->putJson(route('api.admin.users.roles.update', $subscriber), ['roles' => ['admin']])->assertNotFound();
    $this->actingAs($admin)->getJson(route('api.admin.users.permissions.show', $subscriber))->assertNotFound();
    $this->actingAs($admin)->putJson(route('api.admin.users.permissions.update', $subscriber), ['permissions' => []])->assertNotFound();
});

it('hides role-less accounts from the staff endpoints too', function (): void {
    $stranger = User::factory()->create();

    $this->actingAs(staffAdmin())
        ->getJson(route('api.admin.users.show', $stranger))
        ->assertNotFound();
});

it('still resolves staff accounts on every staff endpoint', function (): void {
    $staff = User::factory()->create();
    $staff->assignRole(User::ADMIN);

    $admin = staffAdmin();

    $this->actingAs($admin)->getJson(route('api.admin.users.show', $staff))->assertOk();
    $this->actingAs($admin)->patchJson(route('api.admin.users.update', $staff), ['country' => 'Uganda'])->assertOk();
    $this->actingAs($admin)->getJson(route('api.admin.users.roles.show', $staff))->assertOk();
    $this->actingAs($admin)->getJson(route('api.admin.users.permissions.show', $staff))->assertOk();
    $this->actingAs($admin)->putJson(route('api.admin.users.permissions.update', $staff), ['permissions' => []])->assertOk();
});

it('lists only staff accounts', function (): void {
    $subscriber = User::factory()->create();
    $subscriber->assignRole(User::SUBSCRIBER);
    User::factory()->create(); // role-less

    $admin = staffAdmin();

    $response = $this->actingAs($admin)->getJson(route('api.admin.users.index'))->assertOk();

    $emails = collect($response->json('data'))->pluck('email');

    expect($emails)->toContain($admin->email)
        ->and($emails)->not->toContain($subscriber->email)
        ->and($emails)->toHaveCount(1);
});

it('refuses to create a subscriber account through the staff endpoint', function (): void {
    $this->actingAs(staffAdmin())
        ->postJson(route('api.admin.users.store'), staffPayload(['roles' => ['subscriber']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('roles.0');

    expect(User::where('email', 'grace@example.com')->exists())->toBeFalse();
});

it('creates a staff account and lists it', function (): void {
    $this->actingAs(staffAdmin())
        ->postJson(route('api.admin.users.store'), staffPayload())
        ->assertCreated()
        ->assertJsonPath('data.roles', ['admin']);

    expect(User::where('email', 'grace@example.com')->firstOrFail()->isStaff())->toBeTrue();
});

it('refuses to demote a staff account to subscriber', function (): void {
    $staff = User::factory()->create();
    $staff->assignRole(User::ADMIN);

    $admin = staffAdmin();

    $this->actingAs($admin)
        ->patchJson(route('api.admin.users.update', $staff), ['roles' => ['subscriber']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('roles.0');

    $this->actingAs($admin)
        ->putJson(route('api.admin.users.roles.update', $staff), ['roles' => ['subscriber']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('roles.0');

    expect($staff->fresh()->isStaff())->toBeTrue();
});

it('mirrors the rule: staff accounts are invisible to the subscriber endpoints', function (): void {
    $staff = User::factory()->create();
    $staff->assignRole(User::ADMIN);

    $subscriber = User::factory()->create();
    $subscriber->assignRole(User::SUBSCRIBER);
    Subscription::factory()->for($subscriber)->create();

    $admin = staffAdmin();

    $this->actingAs($admin)->getJson(route('api.admin.subscribers.show', $staff))->assertNotFound();
    $this->actingAs($admin)->getJson(route('api.admin.subscribers.show', $subscriber))->assertOk();
});
