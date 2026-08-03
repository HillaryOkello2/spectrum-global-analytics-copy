<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Payment;
use App\Models\SubscriptionTier;
use App\Models\User;

function registerPaidUser(): Payment
{
    $tier = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);

    test()->postJson(route('api.auth.register'), [
        'first_name' => 'Brian',
        'last_name' => 'Mwangi',
        'phone' => '+254700111222',
        'email' => 'brian@example.com',
        'country' => 'Kenya',
        'password' => 'Str0ngPassword!',
        'password_confirmation' => 'Str0ngPassword!',
        'tier' => $tier->public_id,
        'payment_method' => 'card',
    ])->assertCreated();

    return Payment::firstOrFail();
}

it('activates the account, subscription, and invoice on a successful callback', function (): void {
    $payment = registerPaidUser();

    $this->postJson(route('api.webhooks.payments', 'fake'), [
        'gateway_ref' => $payment->gateway_ref,
        'result' => 'success',
    ])
        ->assertOk()
        ->assertJsonPath('status', PaymentStatus::Successful->value);

    $user = User::where('email', 'brian@example.com')->firstOrFail();

    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->activeSubscription->status)->toBe(SubscriptionStatus::Active)
        ->and($payment->refresh()->invoice->number)->toStartWith('INV-'.now()->year);
});

it('is idempotent for replayed callbacks', function (): void {
    $payment = registerPaidUser();

    $callback = ['gateway_ref' => $payment->gateway_ref, 'result' => 'success'];

    $this->postJson(route('api.webhooks.payments', 'fake'), $callback)->assertOk();
    $this->postJson(route('api.webhooks.payments', 'fake'), $callback)->assertOk();

    expect($payment->refresh()->invoice()->count())->toBe(1)
        ->and(User::where('email', 'brian@example.com')->first()->subscriptions()->count())->toBe(1);
});

it('marks the payment failed on an unsuccessful callback and keeps the user pending', function (): void {
    $payment = registerPaidUser();

    $this->postJson(route('api.webhooks.payments', 'fake'), [
        'gateway_ref' => $payment->gateway_ref,
        'result' => 'failed',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and(User::where('email', 'brian@example.com')->first()->status)->toBe(UserStatus::Pending);
});

it('rejects a callback with an unknown reference', function (): void {
    registerPaidUser();

    $this->postJson(route('api.webhooks.payments', 'fake'), [
        'gateway_ref' => 'FAKE-UNKNOWN',
        'result' => 'success',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'payment_callback_mismatch');
});
