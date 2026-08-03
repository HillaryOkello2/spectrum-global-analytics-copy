<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\User;

function subscriberOn(SubscriptionTier $tier, array $attributes = []): User
{
    $user = User::factory()->create();
    $user->assignRole('subscriber');
    Subscription::factory()->for($user)->create(['tier_id' => $tier->id, ...$attributes]);

    return $user;
}

function settleFakePayment(string $gatewayRef): void
{
    test()->postJson(route('api.webhooks.payments', 'fake'), [
        'gateway_ref' => $gatewayRef,
        'result' => 'success',
    ])->assertOk();
}

// ---------------------------------------------------------------- renewal

it('renews a freemium subscription immediately with no payment', function (): void {
    $tier = SubscriptionTier::factory()->free()->create(['name' => 'Freemium']);
    $user = subscriberOn($tier, ['ends_at' => now()->addDays(5)]);

    $this->actingAs($user)
        ->postJson(route('api.me.subscription.renew'))
        ->assertOk()
        ->assertJsonPath('data.subscription.status', SubscriptionStatus::Active->value);

    // Extended a month from the existing end date (kept the remaining 5 days).
    expect($user->activeSubscription->ends_at->toDateString())
        ->toBe(now()->addDays(5)->addMonth()->toDateString())
        ->and($user->payments()->count())->toBe(0);
});

it('renews a paid subscription through the gateway and extends the term', function (): void {
    $tier = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);
    $user = subscriberOn($tier, ['ends_at' => now()->addDays(3)]);

    $response = $this->actingAs($user)
        ->postJson(route('api.me.subscription.renew'), ['payment_method' => 'mpesa'])
        ->assertStatus(202)
        ->assertJsonPath('data.payment.status', 'pending');

    $payment = Payment::firstOrFail();
    settleFakePayment($payment->gateway_ref);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Successful)
        ->and($user->activeSubscription->tier_id)->toBe($tier->id)
        ->and($user->activeSubscription->ends_at->toDateString())
        ->toBe(now()->addDays(3)->addMonth()->toDateString());
});

// ---------------------------------------------------------------- upgrade

it('upgrades to a higher tier charging the full new-tier price, effective on payment', function (): void {
    $premium = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);
    $platinum = SubscriptionTier::factory()->create(['name' => 'Platinum', 'price' => 199.99]);
    $user = subscriberOn($premium, ['ends_at' => now()->addDays(10)]);

    $response = $this->actingAs($user)
        ->postJson(route('api.me.subscription.upgrade'), ['tier' => $platinum->public_id])
        ->assertStatus(202)
        ->assertJsonPath('data.payment.amount', '199.99');

    // Not switched until payment clears.
    expect($user->activeSubscription->tier_id)->toBe($premium->id);

    $payment = Payment::firstOrFail();
    settleFakePayment($payment->gateway_ref);

    // Now on Platinum with a fresh monthly term.
    $sub = $user->fresh()->activeSubscription;
    expect($sub->tier_id)->toBe($platinum->id)
        ->and($sub->ends_at->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and($payment->refresh()->invoice->amount)->toBe('199.99');
});

it('rejects an upgrade to the same tier', function (): void {
    $premium = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);
    $user = subscriberOn($premium);

    $this->actingAs($user)
        ->postJson(route('api.me.subscription.upgrade'), ['tier' => $premium->public_id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_tier_change');
});

it('rejects an upgrade to a lower-priced tier', function (): void {
    $premium = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);
    $freemium = SubscriptionTier::factory()->free()->create(['name' => 'Freemium']);
    $user = subscriberOn($premium);

    $this->actingAs($user)
        ->postJson(route('api.me.subscription.upgrade'), ['tier' => $freemium->public_id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_tier_change');
});

it('validates the target tier exists', function (): void {
    $premium = SubscriptionTier::factory()->create(['price' => 49.99]);
    $user = subscriberOn($premium);

    $this->actingAs($user)
        ->postJson(route('api.me.subscription.upgrade'), ['tier' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tier');
});

it('forbids anonymous access to renew and upgrade', function (): void {
    $this->postJson(route('api.me.subscription.renew'))->assertUnauthorized();
    $this->postJson(route('api.me.subscription.upgrade'), ['tier' => 'x'])->assertUnauthorized();
});
