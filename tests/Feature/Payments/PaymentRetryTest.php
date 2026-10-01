<?php

use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Models\Payment;
use App\Models\SubscriptionTier;
use App\Models\User;

function signupPayment(): Payment
{
    $tier = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);

    test()->postJson(route('api.auth.register'), [
        'first_name' => 'Wanjiru',
        'last_name' => 'Kamau',
        'phone' => '+254700111222',
        'email' => 'wanjiru@example.com',
        'country' => 'Kenya',
        'password' => 'Str0ngPassword!',
        'password_confirmation' => 'Str0ngPassword!',
        'tier' => $tier->public_id,
        'payment_method' => 'mpesa',
    ])->assertCreated();

    return Payment::firstOrFail();
}

function fakeCallback(Payment $payment, string $result): void
{
    test()->postJson(route('api.webhooks.payments', 'fake'), [
        'gateway_ref' => $payment->gateway_ref,
        'result' => $result,
    ])->assertOk();
}

function failedSignupPayment(): Payment
{
    $payment = signupPayment();
    fakeCallback($payment, 'failed');

    return $payment->refresh();
}

it('lets a signup poll its payment without a token', function (): void {
    $payment = failedSignupPayment();

    $this->getJson(route('api.payments.status', $payment))
        ->assertOk()
        ->assertJsonPath('data.publicId', $payment->public_id)
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.failureReason', 'declined');
});

it('retries a failed signup payment under a new reference, and activates on its callback', function (): void {
    $payment = failedSignupPayment();

    $response = $this->postJson(route('api.payments.retry', $payment))
        ->assertStatus(202)
        ->assertJsonPath('data.payment.status', 'pending')
        ->assertJsonPath('data.payment.method', 'mpesa')
        ->assertJsonPath('data.instructions.type', 'mpesa');

    $retry = Payment::where('public_id', $response->json('data.payment.publicId'))->firstOrFail();

    expect($retry->id)->not->toBe($payment->id)
        ->and($retry->gateway_ref)->not->toBe($payment->gateway_ref)
        ->and($retry->subscription_id)->toBe($payment->subscription_id);

    fakeCallback($retry, 'success');

    expect(User::where('email', 'wanjiru@example.com')->first()->status)->toBe(UserStatus::Active)
        ->and($retry->refresh()->invoice)->not->toBeNull()
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Failed);
});

it('can switch a retry from M-Pesa to card', function (): void {
    $payment = failedSignupPayment();

    $this->postJson(route('api.payments.retry', $payment), ['payment_method' => 'card'])
        ->assertStatus(202)
        ->assertJsonPath('data.payment.method', 'card');
});

it('refuses to retry a payment that has not failed', function (string $state): void {
    $payment = signupPayment();

    if ($state === 'successful') {
        fakeCallback($payment, 'success');
    }

    $this->postJson(route('api.payments.retry', $payment))
        ->assertStatus(409)
        ->assertJsonPath('code', 'payment_not_retryable');
})->with(['pending', 'successful']);

it('refuses to retry a failure that has already been retried', function (): void {
    $payment = failedSignupPayment();

    $this->postJson(route('api.payments.retry', $payment))->assertStatus(202);

    $this->postJson(route('api.payments.retry', $payment))
        ->assertStatus(409)
        ->assertJsonPath('code', 'payment_not_retryable');

    expect(Payment::count())->toBe(2);
});

it('throttles retries on their own counter', function (): void {
    $payment = failedSignupPayment();

    // Polling doesn't use up the retry allowance.
    foreach (range(1, 10) as $_) {
        $this->getJson(route('api.payments.status', $payment))->assertOk();
    }

    $this->postJson(route('api.payments.retry', $payment))->assertStatus(202);

    foreach (range(1, 4) as $_) {
        $this->postJson(route('api.payments.retry', $payment))->assertStatus(409);
    }

    $this->postJson(route('api.payments.retry', $payment))->assertTooManyRequests();
});

it('tells a pending subscriber which payment is outstanding when they log in', function (): void {
    $payment = failedSignupPayment();

    $this->postJson(route('api.auth.login'), [
        'email' => 'wanjiru@example.com',
        'password' => 'Str0ngPassword!',
    ])
        ->assertForbidden()
        ->assertJsonPath('code', 'payment_pending')
        ->assertJsonPath('meta.payment.publicId', $payment->public_id)
        ->assertJsonPath('meta.payment.status', 'failed');
});
