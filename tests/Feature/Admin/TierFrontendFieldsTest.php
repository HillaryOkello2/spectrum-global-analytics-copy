<?php

use App\Models\SubscriptionTier;
use App\Models\User;

function frontendTiersAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

it('exposes featured, description, benefits and isDefaultFree on public tiers', function (): void {
    SubscriptionTier::factory()->defaultFree()->create([
        'name' => 'Freemium',
        'sort_order' => 1,
        'description' => 'Start free.',
        'benefits' => ['10 Daily Briefs'],
    ]);
    SubscriptionTier::factory()->create([
        'name' => 'Premium',
        'price' => 49.99,
        'featured' => true,
        'sort_order' => 2,
        'description' => 'For daily readers.',
        'benefits' => ['Unlimited Daily Briefs'],
    ]);

    $this->getJson(route('api.tiers.index'))
        ->assertOk()
        ->assertJsonPath('data.0.isDefaultFree', true)
        ->assertJsonPath('data.0.featured', false)
        ->assertJsonPath('data.0.description', 'Start free.')
        ->assertJsonPath('data.0.benefits.0', '10 Daily Briefs')
        ->assertJsonPath('data.1.featured', true)
        ->assertJsonPath('data.1.isDefaultFree', false);
});

it('clears featured on every other tier when one is marked featured', function (): void {
    $a = SubscriptionTier::factory()->create(['name' => 'Premium', 'featured' => true]);
    $b = SubscriptionTier::factory()->create(['name' => 'Superior', 'featured' => false]);

    $this->actingAs(frontendTiersAdmin())
        ->patchJson(route('api.admin.tiers.update', $b), ['featured' => true])
        ->assertOk()
        ->assertJsonPath('data.featured', true);

    expect($a->refresh()->featured)->toBeFalse()
        ->and($b->refresh()->featured)->toBeTrue();
});

it('refuses a non-zero price on the default free tier', function (): void {
    $free = SubscriptionTier::factory()->defaultFree()->create(['name' => 'Freemium']);

    $this->actingAs(frontendTiersAdmin())
        ->patchJson(route('api.admin.tiers.update', $free), ['price' => 5])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'default_free_tier_price_locked');
});

it('refuses deactivating the default free tier', function (): void {
    $free = SubscriptionTier::factory()->defaultFree()->create(['name' => 'Freemium']);

    $this->actingAs(frontendTiersAdmin())
        ->patchJson(route('api.admin.tiers.update', $free), ['is_active' => false])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'default_free_tier_price_locked');
});

it('hides unpriced tiers from the public list and rejects signup onto them', function (): void {
    SubscriptionTier::factory()->defaultFree()->create(['name' => 'Freemium']);
    $unpriced = SubscriptionTier::factory()->unpriced()->create(['name' => 'Coming Soon']);

    $this->getJson(route('api.tiers.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->postJson(route('api.auth.register'), [
        'first_name' => 'Tom',
        'last_name' => 'Otieno',
        'phone' => '0712345678',
        'email' => 'tom-unpriced@example.com',
        'country' => 'Kenya',
        'password' => 'Str0ngPassword!',
        'password_confirmation' => 'Str0ngPassword!',
        'tier' => $unpriced->public_id,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'tier_not_priced');
});
