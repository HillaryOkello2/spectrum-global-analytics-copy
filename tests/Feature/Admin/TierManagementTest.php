<?php

use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\User;
use Spatie\Permission\Models\Role;

function tiersAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

it('lists every tier, including ones withdrawn from sale', function (): void {
    $live = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99, 'sort_order' => 1]);
    $withdrawn = SubscriptionTier::factory()->create(['name' => 'Legacy', 'is_active' => false, 'sort_order' => 2]);

    Subscription::factory()->count(2)->create(['tier_id' => $live->id]);

    $this->actingAs(tiersAdmin())
        ->getJson(route('api.admin.tiers.index'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Premium')
        ->assertJsonPath('data.0.subscribersCount', 2)
        ->assertJsonPath('data.1.name', 'Legacy')
        ->assertJsonPath('data.1.isActive', false);

    // An admin has to see the withdrawn one to bring it back; the public page
    // must not.
    $this->getJson(route('api.tiers.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('edits the commercial terms of a tier', function (): void {
    $tier = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);

    $this->actingAs(tiersAdmin())
        ->patchJson(route('api.admin.tiers.update', $tier), [
            'name' => 'Premium Plus',
            'price' => 59.99,
            'sort_order' => 3,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Premium Plus')
        ->assertJsonPath('data.price', '59.99')
        ->assertJsonPath('data.sortOrder', 3);

    $this->assertDatabaseHas('activity_log', ['description' => 'subscription tier updated']);
});

it('leaves a term already paid for on the price it was charged', function (): void {
    $tier = SubscriptionTier::factory()->create(['price' => 49.99]);
    $subscription = Subscription::factory()->create([
        'tier_id' => $tier->id,
        'ends_at' => now()->addMonth(),
    ]);

    $this->actingAs(tiersAdmin())
        ->patchJson(route('api.admin.tiers.update', $tier), ['price' => 89.99])
        ->assertOk();

    // The new price is what the next renewal collects; nothing about the
    // running term changes.
    expect($subscription->refresh()->ends_at->isFuture())->toBeTrue()
        ->and($subscription->tier_id)->toBe($tier->id);
});

it('refuses a tier withdrawn from sale at signup', function (): void {
    $tier = SubscriptionTier::factory()->create(['price' => 49.99]);

    $this->actingAs(tiersAdmin())
        ->patchJson(route('api.admin.tiers.update', $tier), ['is_active' => false])
        ->assertOk();

    $this->postJson(route('api.auth.register'), [
        'first_name' => 'Tom',
        'last_name' => 'Otieno',
        'phone' => '0712345678',
        'email' => 'tom@example.com',
        'country' => 'Kenya',
        'password' => 'Str0ngPassword!',
        'password_confirmation' => 'Str0ngPassword!',
        'tier' => $tier->public_id,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'tier_not_purchasable');
});

it('rejects terms the billing code cannot honour', function (array $payload, string $field): void {
    SubscriptionTier::factory()->create(['name' => 'Taken']);
    $tier = SubscriptionTier::factory()->create(['name' => 'Premium']);

    $this->actingAs(tiersAdmin())
        ->patchJson(route('api.admin.tiers.update', $tier), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'a name another tier holds' => [['name' => 'Taken'], 'name'],
    'a negative price' => [['price' => -1], 'price'],
    // Terms are added a month at a time, so any other period would be a label
    // that does not match what is charged.
    'a billing period the code does not honour' => [['billing_period' => 'yearly'], 'billing_period'],
]);

it('is closed to staff without the tier permission', function (): void {
    $role = Role::create(['name' => 'Proofreader', 'guard_name' => 'web']);
    $role->syncPermissions(['access admin portal', 'proofread products']);

    $staff = User::factory()->create();
    $staff->assignRole($role);

    $tier = SubscriptionTier::factory()->create();

    $this->actingAs($staff)->getJson(route('api.admin.tiers.index'))->assertForbidden();
    $this->actingAs($staff)->patchJson(route('api.admin.tiers.update', $tier), ['price' => 1])->assertForbidden();
});
