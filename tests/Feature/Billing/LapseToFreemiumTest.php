<?php

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Notifications\SubscriptionEnded;
use Illuminate\Support\Facades\Notification;

it('moves a lapsed paid subscription with no pending tier onto Freemium', function (): void {
    Notification::fake();

    $free = SubscriptionTier::factory()->defaultFree()->create(['name' => 'Freemium']);
    $paid = SubscriptionTier::factory()->create(['name' => 'Superior', 'price' => 99.99]);

    $user = User::factory()->create();
    $user->assignRole(User::SUBSCRIBER);
    Subscription::factory()->for($user)->create([
        'tier_id' => $paid->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subDay(),
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    $subscription = $user->fresh()->activeSubscription;

    expect($subscription->tier_id)->toBe($free->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at)->toBeNull()
        ->and($subscription->downgraded_from_tier_name)->toBe('Superior');

    $this->actingAs($user)
        ->getJson(route('api.me.subscription'))
        ->assertOk()
        ->assertJsonPath('data.downgradedFrom.tierName', 'Superior')
        ->assertJsonPath('data.tier.isDefaultFree', true);

    Notification::assertSentTo($user, SubscriptionEnded::class);
});
