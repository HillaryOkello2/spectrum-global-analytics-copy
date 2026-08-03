<?php

namespace App\Services\Access;

use App\Exceptions\Domain\ProtectedRoleException;
use App\Exceptions\Domain\RoleInUseException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Custom staff roles (FR-39): an admin defines a role, picks the permissions it
 * grants, and assigns staff to it.
 *
 * The three seeded roles are structural and immutable — the app keys behaviour
 * off their names (System Admin bypasses every gate, subscriber gates the
 * subscriber portal, admin is the default full-access staff role). Narrowing
 * access is done by creating a new role, never by editing those.
 */
class RoleService
{
    public const PROTECTED_ROLES = [User::SYSTEM_ADMIN, User::ADMIN, User::SUBSCRIBER];

    /**
     * Every role and permission in this app lives on the `web` guard (that is
     * what the seeder created them under). It must be pinned explicitly:
     * `auth:sanctum` swaps the default guard to `sanctum` for the rest of the
     * request, so a role created without it would land on a guard that has no
     * permissions and could never be matched to one.
     */
    public const GUARD = 'web';

    /**
     * @param  array<int, string>  $permissions
     */
    public function create(string $name, array $permissions, User $actor): Role
    {
        return DB::transaction(function () use ($name, $permissions, $actor) {
            $role = Role::create(['name' => $name, 'guard_name' => self::GUARD]);
            $role->syncPermissions($permissions);

            activity()
                ->causedBy($actor)
                ->performedOn($role)
                ->withProperties(['permissions' => $permissions])
                ->log('role created');

            return $role->load('permissions');
        });
    }

    /**
     * Rename a role. Its permissions are managed separately so the role editor
     * and the permission editor stay independent.
     */
    public function rename(Role $role, string $name, User $actor): Role
    {
        $this->guardProtected($role);

        return DB::transaction(function () use ($role, $name, $actor) {
            $previous = $role->name;

            $role->update(['name' => $name]);

            activity()
                ->causedBy($actor)
                ->performedOn($role)
                ->withProperties(['from' => $previous, 'to' => $name])
                ->log('role renamed');

            return $role->load('permissions');
        });
    }

    /**
     * Replace the permissions a role grants. Takes effect immediately for every
     * user holding it.
     *
     * @param  array<int, string>  $permissions
     */
    public function syncPermissions(Role $role, array $permissions, User $actor): Role
    {
        $this->guardProtected($role);

        return DB::transaction(function () use ($role, $permissions, $actor) {
            $previous = $role->permissions->pluck('name')->all();

            $role->syncPermissions($permissions);

            activity()
                ->causedBy($actor)
                ->performedOn($role)
                ->withProperties(['from' => $previous, 'to' => $permissions])
                ->log('role permissions updated');

            return $role->load('permissions');
        });
    }

    public function delete(Role $role, User $actor): void
    {
        $this->guardProtected($role);

        $usersCount = $role->users()->count();

        if ($usersCount > 0) {
            throw new RoleInUseException($usersCount);
        }

        DB::transaction(function () use ($role, $actor): void {
            activity()->causedBy($actor)->performedOn($role)->log('role deleted');

            $role->delete();
        });
    }

    private function guardProtected(Role $role): void
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            throw new ProtectedRoleException;
        }
    }
}
