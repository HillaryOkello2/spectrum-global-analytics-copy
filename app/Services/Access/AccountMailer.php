<?php

namespace App\Services\Access;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\AccountCreated;
use App\Notifications\WelcomeSubscriber;
use App\Support\Audit;
use Throwable;

/**
 * The mail a brand-new account triggers: sign-in details for staff an admin
 * created, a welcome for a subscriber who signed themselves up.
 *
 * Both follow the same rule — the account already exists by the time we get
 * here, so a mail failure is reported and swallowed rather than thrown. Failing
 * the request would only invite a retry that trips the unique-email rule and
 * leaves the caller unable to proceed at all.
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

        Audit::of($user)->with(['email' => $user->email])->log('sign-in details emailed');

        return true;
    }

    /**
     * Welcome a subscriber who has just signed up — freemium and paid alike.
     *
     * `$payment` is the pending gateway payment for a paid signup and null for
     * one that needed none; it decides whether the mail greets an open account
     * or a reserved one.
     */
    public function sendWelcome(User $user, Subscription $subscription, ?Payment $payment = null): bool
    {
        try {
            $user->notify(new WelcomeSubscriber($subscription, $payment));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
