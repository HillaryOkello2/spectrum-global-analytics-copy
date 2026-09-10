<?php

use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\SubscriptionTier;
use App\Models\User;

function registrationPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Amina',
        'last_name' => 'Odhiambo',
        'phone' => '+254712345678',
        'email' => 'amina@example.com',
        'country' => 'Kenya',
        'password' => 'Str0ngPassword!',
        'password_confirmation' => 'Str0ngPassword!',
        ...$overrides,
    ];
}

it('activates a freemium signup immediately without payment', function (): void {
    $tier = SubscriptionTier::factory()->free()->create(['name' => 'Freemium']);

    $response = $this->postJson(route('api.auth.register'), registrationPayload([
        'tier' => $tier->public_id,
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.user.status', UserStatus::Active->value)
        ->assertJsonStructure(['data' => ['token']]);

    $user = User::where('email', 'amina@example.com')->firstOrFail();

    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->activeSubscription->status)->toBe(SubscriptionStatus::Active)
        ->and($user->activeSubscription->ends_at->diffInDays($user->activeSubscription->starts_at->addMonth()))->toBe(0.0)
        ->and($user->payments()->count())->toBe(0);
});

it('leaves a paid signup pending with payment instructions', function (): void {
    $tier = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);

    $response = $this->postJson(route('api.auth.register'), registrationPayload([
        'tier' => $tier->public_id,
        'payment_method' => 'mpesa',
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.payment.status', 'pending')
        ->assertJsonMissingPath('data.token');

    $user = User::where('email', 'amina@example.com')->firstOrFail();

    expect($user->status)->toBe(UserStatus::Pending)
        ->and($user->subscriptions()->first()->status)->toBe(SubscriptionStatus::Pending)
        ->and($user->payments()->first()->gateway_ref)->toStartWith('FAKE-');
});

it('blocks login for payment-pending accounts', function (): void {
    $tier = SubscriptionTier::factory()->create(['price' => 49.99]);

    $this->postJson(route('api.auth.register'), registrationPayload(['tier' => $tier->public_id]))
        ->assertCreated();

    $this->postJson(route('api.auth.login'), [
        'email' => 'amina@example.com',
        'password' => 'Str0ngPassword!',
    ])
        ->assertForbidden()
        ->assertJsonPath('code', 'payment_pending');
});

it('logs in an active subscriber and reports the subscriber portal', function (): void {
    $tier = SubscriptionTier::factory()->free()->create();

    $this->postJson(route('api.auth.register'), registrationPayload(['tier' => $tier->public_id]));

    $this->postJson(route('api.auth.login'), [
        'email' => 'amina@example.com',
        'password' => 'Str0ngPassword!',
    ])
        ->assertOk()
        ->assertJsonPath('data.portal', 'subscriber')
        ->assertJsonStructure(['data' => ['token']]);
});

it('identifies the session and portal for any authenticated role via auth/me', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->getJson(route('api.auth.me'))
        ->assertOk()
        ->assertJsonPath('data.portal', 'admin')
        ->assertJsonPath('data.user.email', $admin->email)
        ->assertJsonMissingPath('data.token');

    $subscriber = User::factory()->create();
    $subscriber->assignRole('subscriber');

    $this->actingAs($subscriber)
        ->getJson(route('api.auth.me'))
        ->assertOk()
        ->assertJsonPath('data.portal', 'subscriber');

    // /me is the user's own profile, open to staff too — they need it to change
    // the temporary password emailed when an admin creates their account.
    $this->actingAs($admin)
        ->getJson(route('api.me.show'))
        ->assertOk()
        ->assertJsonPath('data.email', $admin->email);

    // The genuinely subscriber-only routes stay barred to staff.
    $this->actingAs($admin)->getJson(route('api.me.subscription'))->assertForbidden();
});

it('rejects unauthenticated access to auth/me', function (): void {
    $this->getJson(route('api.auth.me'))->assertUnauthorized();
});

it('rejects registration with an invalid tier', function (): void {
    $this->postJson(route('api.auth.register'), registrationPayload([
        'tier' => 'not-a-real-tier',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tier');
});
