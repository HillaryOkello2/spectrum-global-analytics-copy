<?php

namespace App\Services\Access;

use App\Models\Payment;
use App\Models\User;

/**
 * Links into the frontend apps, for the mail this API-only backend sends.
 *
 * Staff sign in to the admin portal and subscribers to the subscriber portal,
 * so every link is addressed to the app its recipient actually uses. Origins
 * and paths come from config/frontend.php.
 */
class FrontendLinks
{
    public function login(User $user): string
    {
        return $this->to($user, config('frontend.paths.login'));
    }

    /**
     * Carries the email as well as the token: POST /auth/reset-password needs
     * both, and putting it in the link spares the user retyping it.
     */
    public function resetPassword(User $user, string $token): string
    {
        return $this->to($user, config('frontend.paths.reset_password'), [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]);
    }

    /**
     * The subscriber's own subscription page, where renewing and upgrading
     * start. Linked from the expiry notices.
     */
    public function subscription(User $user): string
    {
        return $this->to($user, config('frontend.paths.subscription'));
    }

    /**
     * Where PGW's hosted page sends the payer when they finish. That page only
     * polls GET /payments/{payment}/status: the gateway's callback, not this
     * redirect, settles the payment.
     *
     * The payment id goes in the PATH, not a query parameter: PGW appends its
     * own `checkOut` parameter to whatever URL it was given, and every
     * redirect URL it is known to have been given carries no query string of
     * its own. A path segment survives however that append is done.
     */
    public function paymentReturn(Payment $payment): string
    {
        return $this->to(
            $payment->user,
            rtrim(config('frontend.paths.payment_return'), '/').'/'.$payment->public_id,
        );
    }

    /**
     * @param  array<string, string>  $query
     */
    private function to(User $user, string $path, array $query = []): string
    {
        $origin = $user->isStaff()
            ? config('frontend.admin_url')
            : config('frontend.subscriber_url');

        $url = rtrim((string) $origin, '/').'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
