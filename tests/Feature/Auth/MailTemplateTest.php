<?php

use App\Models\User;
use App\Notifications\AccountCreated;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * The look of the mail, not its words.
 *
 * Every notification this application sends is a Markdown mailable, so they
 * all go through one theme. Pinning it here means a restyle has one place to
 * fail rather than eight.
 */

/** The brand palette, taken from the public site. */
const SPECTRUM_NAVY = '#10172d';

const SPECTRUM_BRASS = '#aa8940';

function renderedMail(): string
{
    $user = User::factory()->create(['first_name' => 'Amina']);

    return (string) (new AccountCreated('Str0ngPassword!'))->toMail($user)->render();
}

it('renders mail in the Spectrum theme, not Laravel\'s default', function (): void {
    $html = strtolower(renderedMail());

    expect($html)
        ->toContain(SPECTRUM_NAVY)
        ->toContain(SPECTRUM_BRASS)
        // Laravel's stock theme: an indigo button, a grey masthead, and the
        // framework's own logo as the brand.
        ->not->toContain('#2d3748')
        ->not->toContain('#3869d4')
        ->not->toContain('#edf2f7')
        ->not->toContain('laravel.com/img/notification-logo');
});

it('signs every email with the product name rather than the framework\'s', function (): void {
    // APP_NAME shipped as "Laravel", so the masthead, the sign-off and the
    // footer all said so.
    expect(config('app.name'))->not->toBe('Laravel');

    // The tagline is not asserted here: with the logo deployed it is part of
    // the image rather than text, and the fallback that does render it as text
    // is covered in BrandLogoTest.
    expect(renderedMail())
        ->toContain(config('app.name'))
        ->not->toContain('>Laravel<');
});

it('writes the password reset email in the house voice, not the framework\'s', function (): void {
    // The one mail the framework composes rather than this application, and
    // the one a subscriber is likeliest to see first.
    $user = User::factory()->create(['first_name' => 'Amina']);

    $html = (string) (new ResetPassword('tok3n'))->toMail($user)->render();
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

    expect(strtolower($html))->toContain(SPECTRUM_NAVY)->toContain(SPECTRUM_BRASS);

    expect($text)
        ->toContain('Hello Amina,')
        ->toContain($user->email)
        // Laravel's stock copy, which opened "Hello!" and explained itself in
        // the passive voice.
        ->not->toContain('You are receiving this email because we received')
        ->not->toContain('no further action is required');
});

it('points the masthead at a page a reader can open', function (): void {
    // Not APP_URL: this backend is API-only and serves no page.
    expect(config('mail.brand.url'))->toBeNull()
        ->and(renderedMail())->toContain(config('frontend.subscriber_url'));
});

it('still delivers the password reset mail it is meant to', function (): void {
    // The reset link is built by AppServiceProvider rather than a route this
    // API does not have; a theme change must not disturb that.
    Notification::fake();

    $user = User::factory()->create();
    $user->assignRole(User::SUBSCRIBER);

    $this->postJson(route('api.auth.forgot-password'), ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return str_starts_with($url, config('frontend.subscriber_url').config('frontend.paths.reset_password'))
            && Password::tokenExists($user, $query['token'] ?? '');
    });
});
