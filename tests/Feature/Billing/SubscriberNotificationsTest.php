<?php

use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Notifications\PaymentFailed;
use App\Notifications\PaymentReceived;
use App\Notifications\SubscriptionEnded;
use App\Notifications\SubscriptionEndingSoon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

function notifiedSignup(): Payment
{
    $tier = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);

    test()->postJson(route('api.auth.register'), [
        'first_name' => 'Nina',
        'last_name' => 'Achieng',
        'phone' => '+254711222333',
        'email' => 'nina@example.com',
        'country' => 'Kenya',
        'password' => 'Str0ngPassword!',
        'password_confirmation' => 'Str0ngPassword!',
        'tier' => $tier->public_id,
        'payment_method' => 'mpesa',
    ])->assertCreated();

    return Payment::firstOrFail();
}

function settleNotified(Payment $payment, string $result = 'success'): void
{
    test()->postJson(route('api.webhooks.payments', 'fake'), [
        'gateway_ref' => $payment->gateway_ref,
        'result' => $result,
    ])->assertOk();
}

function notifiedSubscriber(array $attributes = []): User
{
    $user = User::factory()->create();
    $user->assignRole(User::SUBSCRIBER);

    $tier = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);
    Subscription::factory()->for($user)->create([
        'tier_id' => $tier->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonth(),
        ...$attributes,
    ]);

    return $user;
}

it('emails the invoice as a queued PDF when a signup settles', function (): void {
    Notification::fake();

    settleNotified(notifiedSignup());

    $user = User::where('email', 'nina@example.com')->firstOrFail();

    Notification::assertSentTo($user, PaymentReceived::class, function (PaymentReceived $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $attachment = $mail->rawAttachments[0] ?? null;

        return $notification instanceof ShouldQueue
            && $mail->subject === 'Your subscription is active'
            && $attachment !== null
            && str_starts_with($attachment['data'], '%PDF')
            && str_starts_with($attachment['name'], 'invoice-INV-')
            && $attachment['options']['mime'] === 'application/pdf';
    });
});

it('says renewed on a renewal and upgraded on an upgrade', function (string $action, string $subject): void {
    Notification::fake();

    $user = notifiedSubscriber(['ends_at' => now()->addDays(3)]);
    $target = SubscriptionTier::factory()->create(['name' => 'Platinum', 'price' => 199.99]);

    $this->actingAs($user)->postJson(
        route("api.me.subscription.{$action}"),
        $action === 'upgrade' ? ['tier' => $target->public_id] : [],
    )->assertStatus(202);

    settleNotified(Payment::latest('id')->firstOrFail());

    Notification::assertSentTo($user, PaymentReceived::class,
        fn (PaymentReceived $notification) => $notification->toMail($user)->subject === $subject);
})->with([
    ['renew', 'Your subscription has been renewed'],
    ['upgrade', 'Your subscription has been upgraded'],
]);

it('tells the payer when a payment is declined', function (): void {
    Notification::fake();

    settleNotified(notifiedSignup(), 'failed');

    $user = User::where('email', 'nina@example.com')->firstOrFail();

    Notification::assertSentTo($user, PaymentFailed::class, function (PaymentFailed $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        return $notification instanceof ShouldQueue
            && $mail->subject === 'Your payment did not go through'
            // The link goes to the page that can start a new attempt.
            && str_contains((string) $mail->actionUrl, '/payment/return/');
    });
});

it('tells the payer when an unanswered payment expires', function (): void {
    Notification::fake();

    $payment = notifiedSignup();
    // created_at is not fillable, so it has to be forced.
    $payment->forceFill(['created_at' => now()->subHours(2)])->save();

    $this->artisan('payments:expire-pending')->assertSuccessful();

    Notification::assertSentTo(
        User::where('email', 'nina@example.com')->firstOrFail(),
        PaymentFailed::class,
    );
});

it('warns a week out, again on the last day, and never twice for the same notice', function (): void {
    Notification::fake();

    $user = notifiedSubscriber(['ends_at' => now()->addDays(5)]);
    $subscription = $user->subscriptions()->firstOrFail();

    $this->artisan('subscriptions:remind')->assertSuccessful();
    Notification::assertSentToTimes($user, SubscriptionEndingSoon::class, 1);
    expect($subscription->refresh()->last_reminder_days)->toBe(7);

    // The daily run must not repeat the notice it already sent.
    $this->artisan('subscriptions:remind')->assertSuccessful();
    Notification::assertSentToTimes($user, SubscriptionEndingSoon::class, 1);

    $subscription->update(['ends_at' => now()->addHours(12)]);
    $this->artisan('subscriptions:remind')->assertSuccessful();
    Notification::assertSentToTimes($user, SubscriptionEndingSoon::class, 2);
    expect($subscription->refresh()->last_reminder_days)->toBe(1);
});

it('leaves a subscription with weeks to run alone', function (): void {
    Notification::fake();

    $user = notifiedSubscriber(['ends_at' => now()->addDays(20)]);

    $this->artisan('subscriptions:remind')->assertSuccessful();

    Notification::assertNotSentTo($user, SubscriptionEndingSoon::class);
});

it('starts the notices over when the term is extended', function (): void {
    Notification::fake();

    $user = notifiedSubscriber(['ends_at' => now()->addDays(2)]);
    $subscription = $user->subscriptions()->firstOrFail();

    $this->artisan('subscriptions:remind')->assertSuccessful();
    expect($subscription->refresh()->last_reminder_days)->not->toBeNull();

    $this->actingAs($user)->postJson(route('api.me.subscription.renew'))->assertStatus(202);
    settleNotified(Payment::latest('id')->firstOrFail());

    expect($subscription->refresh()->last_reminder_days)->toBeNull();
});

it('tells a subscriber when the term has actually lapsed', function (): void {
    Notification::fake();

    $user = notifiedSubscriber(['ends_at' => now()->subDay()]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    Notification::assertSentTo($user, SubscriptionEnded::class);
    expect($user->subscriptions()->first()->status)->toBe(SubscriptionStatus::Expired);
});
