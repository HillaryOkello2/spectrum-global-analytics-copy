<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Notifications\SubscriptionDowngradeScheduled;
use App\Notifications\SubscriptionEnded;
use Illuminate\Support\Facades\Notification;

/**
 * A downgrade is the one tier change that must NOT take effect when it is
 * asked for: the subscriber has paid for the term they are in, so the cheaper
 * tier waits for the next billing.
 */
function downgradeTiers(): array
{
    return [
        SubscriptionTier::factory()->free()->create(['name' => 'Freemium', 'sort_order' => 1]),
        SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99, 'sort_order' => 2]),
        SubscriptionTier::factory()->create(['name' => 'Platinum', 'price' => 199.99, 'sort_order' => 3]),
    ];
}

function subscriberOnTier(SubscriptionTier $tier, array $attributes = []): User
{
    $user = User::factory()->create();
    $user->assignRole('subscriber');
    Subscription::factory()->for($user)->create(['tier_id' => $tier->id, ...$attributes]);

    return $user;
}

// ------------------------------------------------------------- scheduling

it('schedules a downgrade without charging or changing anything today', function (): void {
    Notification::fake();

    [$freemium, $premium] = downgradeTiers();
    $user = subscriberOnTier($premium, ['ends_at' => now()->addDays(12)]);

    $this->actingAs($user)
        ->postJson(route('api.me.subscription.downgrade'), ['tier' => $freemium->public_id])
        ->assertOk()
        ->assertJsonPath('data.subscription.tier.name', 'Premium')
        ->assertJsonPath('data.subscription.pendingTier.name', 'Freemium')
        ->assertJsonPath(
            'data.subscription.pendingTierEffectiveAt',
            now()->addDays(12)->format('Y-m-d H:i:s'),
        );

    $subscription = $user->fresh()->activeSubscription;

    // Still Premium, still running, and nothing was asked of the gateway.
    expect($subscription->tier_id)->toBe($premium->id)
        ->and($subscription->pending_tier_id)->toBe($freemium->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($user->payments()->count())->toBe(0);

    Notification::assertSentTo($user, SubscriptionDowngradeScheduled::class);
});

it('tells the subscriber the date it lands and that nothing has changed yet', function (): void {
    [$freemium, $premium] = downgradeTiers();
    $user = subscriberOnTier($premium, ['ends_at' => now()->addDays(12)]);
    $subscription = $user->activeSubscription;
    $subscription->update(['pending_tier_id' => $freemium->id]);

    $mail = (new SubscriptionDowngradeScheduled($subscription->fresh(), $freemium))->toMail($user);
    $text = html_entity_decode(strip_tags((string) $mail->render()), ENT_QUOTES | ENT_HTML5);

    expect($text)->toContain('Nothing changes today')
        ->toContain(now()->addDays(12)->format('j F Y'))
        ->toContain('Freemium')
        ->toContain('Premium');
});

it('refuses a downgrade to the same or a higher tier', function (): void {
    [, $premium, $platinum] = downgradeTiers();
    $user = subscriberOnTier($premium);

    $this->actingAs($user)
        ->postJson(route('api.me.subscription.downgrade'), ['tier' => $platinum->public_id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_tier_change');

    // Same tier, with nothing scheduled, is not a change at all.
    $this->actingAs($user)
        ->postJson(route('api.me.subscription.downgrade'), ['tier' => $premium->public_id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_tier_change');
});

it('refuses a downgrade on a subscription that never started', function (): void {
    [$freemium, $premium] = downgradeTiers();
    $user = User::factory()->create();
    $user->assignRole('subscriber');
    Subscription::factory()->for($user)->pending()->create(['tier_id' => $premium->id]);

    $this->actingAs($user)
        ->postJson(route('api.me.subscription.downgrade'), ['tier' => $freemium->public_id])
        ->assertStatus(403)
        ->assertJsonPath('code', 'subscription_not_active');
});

it('validates that the target tier exists', function (): void {
    [, $premium] = downgradeTiers();
    $user = subscriberOnTier($premium);

    $this->actingAs($user)
        ->postJson(route('api.me.subscription.downgrade'), ['tier' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tier');
});

it('forbids anonymous access to downgrade and its cancellation', function (): void {
    $this->postJson(route('api.me.subscription.downgrade'), ['tier' => 'x'])->assertUnauthorized();
    $this->deleteJson(route('api.me.subscription.downgrade.cancel'))->assertUnauthorized();
});

// ---------------------------------------------------------- cancellation

it('cancels a scheduled downgrade', function (): void {
    [$freemium, $premium] = downgradeTiers();
    $user = subscriberOnTier($premium, ['pending_tier_id' => $freemium->id]);

    $this->actingAs($user)
        ->deleteJson(route('api.me.subscription.downgrade.cancel'))
        ->assertOk()
        ->assertJsonPath('data.subscription.pendingTier', null);

    expect($user->fresh()->activeSubscription->pending_tier_id)->toBeNull();
});

it('treats choosing the current tier again as calling the downgrade off', function (): void {
    // The frontend offers one tier picker; picking the tier you are on is how
    // a subscriber changes their mind.
    [$freemium, $premium] = downgradeTiers();
    $user = subscriberOnTier($premium, ['pending_tier_id' => $freemium->id]);

    $this->actingAs($user)
        ->postJson(route('api.me.subscription.downgrade'), ['tier' => $premium->public_id])
        ->assertOk()
        ->assertJsonPath('data.subscription.pendingTier', null);

    expect($user->fresh()->activeSubscription->pending_tier_id)->toBeNull();
});

// -------------------------------------------------------------- landing

it('renews onto the cheaper tier, at the cheaper price', function (): void {
    [, $premium, $platinum] = downgradeTiers();
    $user = subscriberOnTier($platinum, [
        'ends_at' => now()->addDays(4),
        'pending_tier_id' => $premium->id,
    ]);

    // Priced against Premium (49.99), not the Platinum they are leaving.
    $this->actingAs($user)
        ->postJson(route('api.me.subscription.renew'), ['payment_method' => 'mpesa'])
        ->assertStatus(202)
        ->assertJsonPath('data.payment.amount', '49.99');

    // Not applied until it clears.
    expect($user->fresh()->activeSubscription->tier_id)->toBe($platinum->id);

    $payment = Payment::firstOrFail();
    $this->postJson(route('api.webhooks.payments', 'fake'), [
        'gateway_ref' => $payment->gateway_ref,
        'result' => 'success',
    ])->assertOk();

    $subscription = $user->fresh()->activeSubscription;

    expect($payment->refresh()->status)->toBe(PaymentStatus::Successful)
        ->and($subscription->tier_id)->toBe($premium->id)
        ->and($subscription->pending_tier_id)->toBeNull()
        // Extended from the old end date: a scheduled downgrade never costs
        // the days already paid for, unlike an upgrade's fresh term.
        ->and($subscription->ends_at->toDateString())
        ->toBe(now()->addDays(4)->addMonth()->toDateString())
        ->and($payment->refresh()->invoice->amount)->toBe('49.99');
});

it('renews a downgrade to a free tier immediately, with no payment', function (): void {
    [$freemium, $premium] = downgradeTiers();
    $user = subscriberOnTier($premium, [
        'ends_at' => now()->addDays(3),
        'pending_tier_id' => $freemium->id,
    ]);

    $this->actingAs($user)
        ->postJson(route('api.me.subscription.renew'))
        ->assertOk()
        ->assertJsonPath('data.subscription.tier.name', 'Freemium');

    $subscription = $user->fresh()->activeSubscription;

    expect($subscription->tier_id)->toBe($freemium->id)
        ->and($subscription->pending_tier_id)->toBeNull()
        ->and($user->payments()->count())->toBe(0);
});

it('rolls a lapsed term onto a scheduled free tier instead of expiring it', function (): void {
    Notification::fake();

    [$freemium, $premium] = downgradeTiers();
    $user = subscriberOnTier($premium, [
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subDay(),
        'pending_tier_id' => $freemium->id,
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    $subscription = $user->fresh()->activeSubscription;

    // Freemium costs nothing, so there is nothing to wait for: the account
    // stays open on the tier they asked for.
    expect($subscription)->not->toBeNull()
        ->and($subscription->tier_id)->toBe($freemium->id)
        ->and($subscription->pending_tier_id)->toBeNull()
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at->toDateString())->toBe(now()->addMonth()->toDateString());

    Notification::assertNotSentTo($user, SubscriptionEnded::class);
});

it('still expires a lapsed term whose scheduled tier has to be paid for', function (): void {
    Notification::fake();

    [, $premium, $platinum] = downgradeTiers();
    $user = subscriberOnTier($platinum, [
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subDay(),
        'pending_tier_id' => $premium->id,
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    $subscription = $user->subscriptions()->latest('id')->first();

    // Premium is not free, so it waits for a renewal to pay for it — and the
    // schedule survives the expiry so that renewal still lands on it.
    expect($subscription->status)->toBe(SubscriptionStatus::Expired)
        ->and($subscription->tier_id)->toBe($platinum->id)
        ->and($subscription->pending_tier_id)->toBe($premium->id);

    Notification::assertSentTo($user, SubscriptionEnded::class);
});

it('cancels a scheduled downgrade when an upgrade is paid for instead', function (): void {
    [$freemium, $premium, $platinum] = downgradeTiers();
    $user = subscriberOnTier($premium, [
        'ends_at' => now()->addDays(10),
        'pending_tier_id' => $freemium->id,
    ]);

    $this->actingAs($user)
        ->postJson(route('api.me.subscription.upgrade'), ['tier' => $platinum->public_id])
        ->assertStatus(202);

    $payment = Payment::firstOrFail();
    $this->postJson(route('api.webhooks.payments', 'fake'), [
        'gateway_ref' => $payment->gateway_ref,
        'result' => 'success',
    ])->assertOk();

    $subscription = $user->fresh()->activeSubscription;

    // Paying to move up overtakes the move down, and the upgrade keeps its own
    // fresh-term behaviour.
    expect($subscription->tier_id)->toBe($platinum->id)
        ->and($subscription->pending_tier_id)->toBeNull()
        ->and($subscription->ends_at->toDateString())->toBe(now()->addMonth()->toDateString());
});

it('reports no pending tier on an ordinary subscription', function (): void {
    [, $premium] = downgradeTiers();
    $user = subscriberOnTier($premium);

    $this->actingAs($user)
        ->getJson(route('api.me.subscription'))
        ->assertOk()
        ->assertJsonPath('data.pendingTier', null)
        ->assertJsonPath('data.pendingTierEffectiveAt', null);
});
