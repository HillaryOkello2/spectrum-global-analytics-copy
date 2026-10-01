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
    public function create(string $name, array $permissions, User $actor, ?string $description = null): Role
    {
        return DB::transaction(function () use ($name, $permissions, $actor, $description) {
            $role = Role::create([
                'name' => $name,
                'description' => $description,
                'guard_name' => self::GUARD,
            ]);
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
     * Rename a role, and nothing else.
     */
    public function rename(Role $role, string $name, User $actor): Role
    {
        return $this->update($role, ['name' => $name], $actor);
    }

    /**
     * Change a role's name and/or description. Only the keys supplied are
     * written, so an editor that omits one leaves it as it was. Permissions are
     * managed separately, so the role editor and the permission editor stay
     * independent.
     *
     * @param  array<string, mixed>  $attributes  'name' and/or 'description'
     */
    public function update(Role $role, array $attributes, User $actor): Role
    {
        $this->guardProtected($role);

        $changes = array_intersect_key($attributes, array_flip(['name', 'description']));

        return DB::transaction(function () use ($role, $changes, $actor) {
            $previous = $role->only(array_keys($changes));

            $role->update($changes);

            activity()
                ->causedBy($actor)
                ->performedOn($role)
                ->withProperties(['from' => $previous, 'to' => $changes])
                ->log('role updated');

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
