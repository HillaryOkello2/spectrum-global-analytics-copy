<?php

namespace App\Services\Access;

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
