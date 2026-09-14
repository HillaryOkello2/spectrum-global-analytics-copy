<?php

namespace App\Services\Access;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Access\DTOs\CreatedStaffAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates staff accounts (FR-39).
 */
class StaffAccountService
{
    public function __construct(
        private readonly UserAccessService $access,
        private readonly AccountMailer $mailer,
    ) {}

    /**
     * Create a staff account with a generated password and email it to the new
     * user. The admin never chooses the password and normally never sees it:
     * it comes back only when the email fails, so they can pass it on.
     *
     * @param  array{first_name: string, last_name: string, phone: string, country: string, email: string}  $attributes
     * @param  array<int, string>  $roles
     */
    public function create(array $attributes, array $roles, User $admin): CreatedStaffAccount
    {
        $password = Str::password(16);

        // One transaction, so roles the admin may not grant (a System Admin,
        // say) leave no half-made account behind to hold the email address.
        $user = DB::transaction(function () use ($attributes, $roles, $admin, $password): User {
            $user = User::create([
                ...$attributes,
                'password' => $password,
                'status' => UserStatus::Active,
            ]);

            activity()->causedBy($admin)->performedOn($user)->log('admin user created');

            return $this->access->syncRoles($user, $roles, $admin);
        });

        // After the roles are synced: the sign-in link is addressed to the
        // admin or subscriber app according to them.
        $emailed = $this->mailer->sendAccountCreated($user, $password);

        return new CreatedStaffAccount($user, $emailed, $emailed ? null : $password);
    }
}
