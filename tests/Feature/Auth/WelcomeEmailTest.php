<?php

use App\Enums\AccessType;
use App\Models\Component;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\TierAllocation;
use App\Models\User;
use App\Notifications\WelcomeSubscriber;
use Illuminate\Support\Facades\Notification;

function signupPayload(SubscriptionTier $tier, array $overrides = []): array
{
    return [
        'first_name' => 'Amina',
        'last_name' => 'Otieno',
        'email' => 'amina@example.com',
        'phone' => '+254722000111',
        'country' => 'Kenya',
        'password' => 'Str0ngPassword!',
        'password_confirmation' => 'Str0ngPassword!',
        'tier' => $tier->public_id,
        ...$overrides,
    ];
}

/** The rendered mail as plain text, for asserting on what the reader sees. */
function mailText(WelcomeSubscriber $notification, User $user): string
{
    return html_entity_decode(
        strip_tags((string) $notification->toMail($user)->render()),
        ENT_QUOTES | ENT_HTML5,
    );
}

it('welcomes a freemium subscriber, who pays nothing and so hears nothing else', function (): void {
    Notification::fake();

    $tier = SubscriptionTier::factory()->free()->create(['name' => 'Freemium']);
    $component = Component::factory()->create(['name' => 'Daily Strategic Intelligence Analytics Brief']);
    TierAllocation::factory()->create([
        'tier_id' => $tier->id,
        'component_id' => $component->id,
        'access_type' => AccessType::Metered,
        'monthly_limit' => 10,
    ]);

    $this->postJson(route('api.auth.register'), signupPayload($tier))->assertCreated();

    $user = User::where('email', 'amina@example.com')->firstOrFail();

    Notification::assertSentTo($user, WelcomeSubscriber::class, function (WelcomeSubscriber $notification) use ($user): bool {
        $text = mailText($notification, $user);

        return str_contains($text, 'Welcome, Amina.')
            && str_contains($text, 'active on the Freemium tier')
            // What the tier opens, in words rather than access-type codes.
            && str_contains($text, 'Daily Strategic Intelligence Analytics Brief — up to 10 a month')
            && str_contains($text, 'amina@example.com');
    });

    Notification::assertSentTimes(WelcomeSubscriber::class, 1);
});

it('welcomes a paid signup without promising access it has not paid for', function (): void {
    Notification::fake();

    $tier = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);

    $this->postJson(route('api.auth.register'), signupPayload($tier, ['payment_method' => 'mpesa']))
        ->assertCreated();

    $user = User::where('email', 'amina@example.com')->firstOrFail();

    Notification::assertSentTo($user, WelcomeSubscriber::class, function (WelcomeSubscriber $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $text = html_entity_decode(strip_tags((string) $mail->render()), ENT_QUOTES | ENT_HTML5);

        return str_contains($text, 'reserved your account on the Premium tier')
            // It must not say the subscription is open: it is not.
            && ! str_contains($text, 'is active on the')
            && str_contains($mail->actionText, 'Complete your payment')
            // Pointed at the page that polls and retries the payment.
            && str_starts_with(
                $mail->actionUrl,
                config('frontend.subscriber_url').config('frontend.paths.payment_return'),
            );
    });
});

it('does not fail a signup when the welcome email cannot be sent', function (): void {
    // The account exists by then; a 500 here would only invite a retry that
    // trips the unique-email rule.
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
        'mail.mailers.smtp.timeout' => 1,
        'queue.default' => 'sync',
    ]);

    $tier = SubscriptionTier::factory()->free()->create(['name' => 'Freemium']);

    $this->postJson(route('api.auth.register'), signupPayload($tier))
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'amina@example.com');

    expect(User::where('email', 'amina@example.com')->exists())->toBeTrue();
});

it('says nothing about tier contents when the tier unlocks nothing yet', function (): void {
    $tier = SubscriptionTier::factory()->free()->create(['name' => 'Freemium']);
    $user = User::factory()->create(['first_name' => 'Amina']);
    $subscription = Subscription::factory()->for($user)->create(['tier_id' => $tier->id]);

    // An empty "Your tier includes:" heading reads worse than no heading.
    expect(mailText(new WelcomeSubscriber($subscription), $user))
        ->not->toContain('Your tier includes');
});
