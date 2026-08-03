<?php

namespace App\Providers;

use App\Models\User;
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
    }
}
