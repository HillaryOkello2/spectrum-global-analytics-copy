<?php

namespace App\Services\Access;

use App\Exceptions\Domain\LastSystemAdminException;
use App\Exceptions\Domain\RoleEscalationException;
use App\Exceptions\Domain\SelfAccessChangeException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Role and permission assignment for staff accounts (FR-39).
 *
 * Every path that changes a user's access runs through here so the same three
 * guards always apply: no self-modification, no privilege escalation into
 * System Admin, and the last System Admin cannot be demoted.
 */
class UserAccessService
{
    /**
     * Replace a user's roles wholesale.
     *
     * @param  array<int, string>  $roles
     */
    public function syncRoles(User $user, array $roles, User $actor): User
    {
        $this->guardSelf($user, $actor);
        $this->guardSystemAdminGrant($user, $roles, $actor);
        $this->guardLastSystemAdmin($user, $roles);

        return DB::transaction(function () use ($user, $roles, $actor) {
            $previous = $user->getRoleNames()->all();

            $user->syncRoles($roles);

            activity()
                ->causedBy($actor)
                ->performedOn($user)
                ->withProperties(['from' => $previous, 'to' => $roles])
                ->log('roles updated');

            return $user->refresh();
        });
    }

    /**
     * Replace a user's direct permissions — those granted on top of whatever
     * their roles already carry.
     *
     * @param  array<int, string>  $permissions
     */
    public function syncPermissions(User $user, array $permissions, User $actor): User
    {
        $this->guardSelf($user, $actor);

        return DB::transaction(function () use ($user, $permissions, $actor) {
            $previous = $user->getDirectPermissions()->pluck('name')->all();

            $user->syncPermissions($permissions);

            activity()
                ->causedBy($actor)
                ->performedOn($user)
                ->withProperties(['from' => $previous, 'to' => $permissions])
                ->log('permissions updated');

            return $user->refresh();
        });
    }

    /**
     * Self-modification is blocked outright: it is the simplest route to both
     * privilege escalation and locking yourself out of the admin portal.
     */
    private function guardSelf(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new SelfAccessChangeException;
        }
    }

    /**
     * Granting or revoking System Admin is reserved to System Admins — without
     * this, any holder of `manage users` could promote themselves sideways by
     * creating a System Admin account.
     *
     * @param  array<int, string>  $roles
     */
    private function guardSystemAdminGrant(User $user, array $roles, User $actor): void
    {
        $touchesSystemAdmin = in_array(User::SYSTEM_ADMIN, $roles, true)
            || $user->hasRole(User::SYSTEM_ADMIN);

        if ($touchesSystemAdmin && ! $actor->hasRole(User::SYSTEM_ADMIN)) {
            throw new RoleEscalationException;
        }
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function guardLastSystemAdmin(User $user, array $roles): void
    {
        $isDemotion = $user->hasRole(User::SYSTEM_ADMIN)
            && ! in_array(User::SYSTEM_ADMIN, $roles, true);

        if ($isDemotion && User::role(User::SYSTEM_ADMIN)->count() <= 1) {
            throw new LastSystemAdminException;
        }
    }
}
