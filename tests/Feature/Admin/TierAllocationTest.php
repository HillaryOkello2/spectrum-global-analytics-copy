<?php

use App\Enums\AccessType;
use App\Models\Component;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\TierAllocation;
use App\Models\User;

function allocationsAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

function tierWith(array $allocations = []): SubscriptionTier
{
    $tier = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);

    foreach ($allocations as $componentId => [$access, $limit]) {
        TierAllocation::factory()->create([
            'tier_id' => $tier->id,
            'component_id' => $componentId,
            'access_type' => $access,
            'monthly_limit' => $limit,
        ]);
    }

    return $tier;
}

it('replaces the whole access matrix in one call', function (): void {
    $daily = Component::factory()->create(['code' => 'DB']);
    $weekly = Component::factory()->create(['code' => 'WH']);
    $monthly = Component::factory()->create(['code' => 'MF']);

    $tier = tierWith([$daily->id => [AccessType::Unlimited, null]]);

    $this->actingAs(allocationsAdmin())
        ->putJson(route('api.admin.tiers.allocations.update', $tier), [
            'allocations' => [
                ['component' => 'DB', 'access_type' => 'metered', 'monthly_limit' => 10],
                ['component' => $weekly->public_id, 'access_type' => 'unlimited'],
            ],
        ])
        ->assertOk();

    $matrix = $tier->refresh()->allocations->mapWithKeys(
        fn (TierAllocation $allocation) => [$allocation->component->code => [
            $allocation->access_type->value,
            $allocation->monthly_limit,
        ]],
    );

    // Components may be named by code or publicId; anything left out is dropped.
    expect($matrix['DB'])->toBe(['metered', 10])
        ->and($matrix['WH'])->toBe(['unlimited', null])
        ->and($matrix)->not->toHaveKey($monthly->code);

    $this->assertDatabaseHas('activity_log', ['description' => 'tier allocations updated']);
});

it('changes what a subscriber can actually read', function (): void {
    $component = Component::factory()->create(['code' => 'DB']);
    $product = Product::factory()->for($component)->published()->create();

    $tier = tierWith([$component->id => [AccessType::Denied, null]]);

    $subscriber = User::factory()->create();
    $subscriber->assignRole(User::SUBSCRIBER);
    Subscription::factory()->for($subscriber)->create(['tier_id' => $tier->id]);

    // Denied: the abstract only.
    $this->actingAs($subscriber)
        ->getJson(route('api.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.locked', true);

    $this->actingAs(allocationsAdmin())
        ->putJson(route('api.admin.tiers.allocations.update', $tier), [
            'allocations' => [['component' => 'DB', 'access_type' => 'unlimited']],
        ])
        ->assertOk();

    $this->actingAs($subscriber)
        ->getJson(route('api.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.locked', false)
        ->assertJsonPath('meta.access', 'unlimited');
});

it('refuses a matrix that contradicts itself', function (array $allocation, string $field): void {
    Component::factory()->create(['code' => 'DB']);
    $tier = tierWith();

    $this->actingAs(allocationsAdmin())
        ->putJson(route('api.admin.tiers.allocations.update', $tier), ['allocations' => [$allocation]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'metered with no limit' => [['component' => 'DB', 'access_type' => 'metered'], 'allocations.0.monthly_limit'],
    'unlimited with a limit' => [['component' => 'DB', 'access_type' => 'unlimited', 'monthly_limit' => 5], 'allocations.0.monthly_limit'],
    'a component that does not exist' => [['component' => 'ZZ', 'access_type' => 'unlimited'], 'allocations.0.component'],
    'an access type that does not exist' => [['component' => 'DB', 'access_type' => 'sometimes'], 'allocations.0.access_type'],
]);

it('refuses the same component twice', function (): void {
    Component::factory()->create(['code' => 'DB']);
    $tier = tierWith();

    $this->actingAs(allocationsAdmin())
        ->putJson(route('api.admin.tiers.allocations.update', $tier), [
            'allocations' => [
                ['component' => 'DB', 'access_type' => 'unlimited'],
                ['component' => 'DB', 'access_type' => 'denied'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('allocations.1.component');
});

it('accepts an empty matrix, which opens nothing', function (): void {
    $component = Component::factory()->create(['code' => 'DB']);
    $tier = tierWith([$component->id => [AccessType::Unlimited, null]]);

    $this->actingAs(allocationsAdmin())
        ->putJson(route('api.admin.tiers.allocations.update', $tier), ['allocations' => []])
        ->assertOk();

    expect($tier->refresh()->allocations)->toBeEmpty();
});
