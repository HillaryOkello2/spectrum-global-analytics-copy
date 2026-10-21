<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Access\FrontendLinks;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // House convention: System Admin bypasses all gates/policies.
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole(User::SYSTEM_ADMIN) ? true : null;
        });

        // /admin/users addresses staff, /admin/subscribers addresses subscribers.
        // Anything outside the addressed population is 404, not 403 — it is
        // simply not a member of that collection.
        Route::bind('user', fn (string $value) => User::staff()->where('public_id', $value)->firstOrFail());
        Route::bind('subscriber', fn (string $value) => User::subscriber()->where('public_id', $value)->firstOrFail());

        // Roles have no public_id — the API addresses them by name throughout.
        Route::bind('role', fn (string $value) => Role::where('name', $value)->firstOrFail());

        // Without this the reset mail builds its link from a `password.reset`
        // route, which an API-only app does not have — sending threw, and
        // POST /auth/forgot-password returned a 500 without mailing anything.
        ResetPassword::createUrlUsing(
            fn (User $user, string $token) => app(FrontendLinks::class)->resetPassword($user, $token),
        );

        // The reset mail is the one the framework composes rather than this
        // application, and the one a subscriber is likeliest to see first. Its
        // stock copy opens "Hello!" and explains itself in the passive voice;
        // this says the same things in the house voice, by name, and keeps the
        // framework's own token and expiry handling.
        ResetPassword::toMailUsing(function (User $user, string $token): MailMessage {
            $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('Reset your '.config('app.name').' password')
                ->greeting("Hello {$user->first_name},")
                ->line('Someone asked to reset the password for the account held at '.$user->email.'.')
                ->action('Choose a new password', app(FrontendLinks::class)->resetPassword($user, $token))
                ->line("This link works once, and expires in {$minutes} minutes.")
                ->line('If this was not you, no action is needed — your password has not changed, '
                    .'and the link above is useless to anyone who cannot read this inbox.');
        });
    }
}
