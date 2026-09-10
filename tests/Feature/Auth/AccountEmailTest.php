<?php

use App\Models\User;
use App\Notifications\AccountCreated;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function mailTestAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

function newStaffPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Grace',
        'last_name' => 'Wanjiru',
        'phone' => '+254733112233',
        'country' => 'Kenya',
        'email' => 'grace@example.com',
        'password' => 'Str0ngPassword!',
        'roles' => [User::ADMIN],
        ...$overrides,
    ];
}

/**
 * Point mail at a port nothing listens on, so a send fails for real rather
 * than through a fake.
 */
function breakMail(): void
{
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
        'mail.mailers.smtp.timeout' => 1,
    ]);
}

it('emails a new staff member their sign-in details', function (): void {
    Notification::fake();

    $this->actingAs(mailTestAdmin())
        ->postJson(route('api.admin.users.store'), newStaffPayload())
        ->assertCreated()
        ->assertJsonPath('meta.accountEmailSent', true);

    $user = User::where('email', 'grace@example.com')->firstOrFail();

    Notification::assertSentTo($user, AccountCreated::class, function (AccountCreated $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $text = html_entity_decode(strip_tags((string) $mail->render()), ENT_QUOTES | ENT_HTML5);

        return str_contains($text, 'grace@example.com')
            && str_contains($text, 'Str0ngPassword!')
            // Staff sign in to the admin app, not the subscriber one.
            && $mail->actionUrl === config('frontend.admin_url').config('frontend.paths.login');
    });

    // Only the new user — never the admin who created the account.
    Notification::assertSentTimes(AccountCreated::class, 1);
});

it('shows the password exactly as set, markdown characters and all', function (): void {
    $user = User::factory()->create();
    $user->assignRole(User::ADMIN);

    // Mail is rendered through Markdown. Unescaped, the `_` and `*` here would
    // be read as emphasis and the user shown a password that does not work.
    $password = 'Pa_ss*w&rd<1>`x~[y]\\z_';

    $html = (string) (new AccountCreated($password))->toMail($user)->render();

    expect(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5))->toContain($password);
});

it('still creates the account when the email cannot be sent', function (): void {
    breakMail();

    $this->actingAs(mailTestAdmin())
        ->postJson(route('api.admin.users.store'), newStaffPayload())
        ->assertCreated()
        // The admin needs to know, so they can pass the password on themselves.
        ->assertJsonPath('meta.accountEmailSent', false);

    expect(User::where('email', 'grace@example.com')->exists())->toBeTrue();
});

it('lets a staff member change their own password', function (): void {
    $staff = User::factory()->create(['password' => 'Str0ngPassword!']);
    $staff->assignRole(User::ADMIN);

    // A real token rather than actingAs(): the controller revokes every token
    // except the one making the request, so there has to be a current one.
    $token = $staff->createToken('session')->plainTextToken;
    $staff->createToken('other-device');

    $this->withToken($token)
        ->putJson(route('api.me.password'), [
            'current_password' => 'Str0ngPassword!',
            'password' => 'An0therStr0ng!Pass',
            'password_confirmation' => 'An0therStr0ng!Pass',
        ])
        ->assertOk();

    // Signed out on every other device, and the new password is the one that
    // works. Checked directly rather than through /auth/login: the request
    // above switched this app instance's default guard to sanctum, which a
    // fresh production request never carries over into login.
    expect($staff->tokens()->count())->toBe(1)
        ->and(Hash::check('An0therStr0ng!Pass', $staff->fresh()->password))->toBeTrue()
        ->and(Hash::check('Str0ngPassword!', $staff->fresh()->password))->toBeFalse();
});

it('mails a reset link into the frontend app the user signs in to', function (): void {
    Notification::fake();

    $staff = User::factory()->create();
    $staff->assignRole(User::ADMIN);

    $subscriber = User::factory()->create();
    $subscriber->assignRole(User::SUBSCRIBER);

    foreach ([[$staff, 'frontend.admin_url'], [$subscriber, 'frontend.subscriber_url']] as [$user, $origin]) {
        $this->postJson(route('api.auth.forgot-password'), ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, $origin): bool {
            // Building the mail is where this used to throw: an API-only app
            // has no `password.reset` route for the default link to use.
            $url = $notification->toMail($user)->actionUrl;

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_starts_with($url, config($origin).config('frontend.paths.reset_password'))
                && $query['email'] === $user->email
                && Password::tokenExists($user, $query['token']);
        });
    }
});

it('resets a password with the emailed token', function (): void {
    $user = User::factory()->create(['password' => 'Str0ngPassword!']);
    $user->assignRole(User::SUBSCRIBER);

    $token = Password::createToken($user);

    $this->postJson(route('api.auth.reset-password'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'An0therStr0ng!Pass',
        'password_confirmation' => 'An0therStr0ng!Pass',
    ])->assertOk();

    $this->postJson(route('api.auth.login'), [
        'email' => $user->email,
        'password' => 'An0therStr0ng!Pass',
    ])->assertOk();
});

it('answers forgot-password identically when mail is down', function (): void {
    // Otherwise a registered address would 500 and an unknown one would not,
    // telling anyone probing the endpoint which emails have accounts.
    breakMail();

    $user = User::factory()->create();

    $known = $this->postJson(route('api.auth.forgot-password'), ['email' => $user->email]);
    $unknown = $this->postJson(route('api.auth.forgot-password'), ['email' => 'nobody@example.com']);

    $known->assertOk();
    $unknown->assertOk();

    expect($known->json('message'))->toBe($unknown->json('message'));
});
