<?php

namespace App\Services\Access\DTOs;

use App\Models\User;

readonly class CreatedStaffAccount
{
    /**
     * @param  string|null  $temporaryPassword  Set only when the email failed, so the
     *                                          admin can pass the password on. Null
     *                                          once it has been emailed.
     */
    public function __construct(
        public User $user,
        public bool $emailed,
        #[\SensitiveParameter] public ?string $temporaryPassword = null,
    ) {}
}
