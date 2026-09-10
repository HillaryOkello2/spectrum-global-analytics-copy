<?php

namespace App\Services\Access;

use App\Models\User;
use App\Notifications\AccountCreated;
use Throwable;

/**
 * Emails a new staff member their sign-in details.
 */
class AccountMailer
{
    /**
     * Returns whether the email went out. A mail failure is reported rather
     * than thrown: the account already exists by this point, and failing the
     * request would only prompt a retry that trips the unique-email rule.
     */
    public function sendAccountCreated(User $user, #[\SensitiveParameter] string $password): bool
    {
        try {
            $user->notify(new AccountCreated($password));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        activity()->performedOn($user)->log('sign-in details emailed');

        return true;
    }
}
