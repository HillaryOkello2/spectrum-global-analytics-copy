<?php

namespace App\Http\Controllers\Api\V1\Admin\Roles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncRolePermissionsRequest;
use App\Http\Resources\RoleResource;
use App\Services\Access\RoleService;
use Spatie\Permission\Models\Role;

/**
 * @group Admin Portal
 *
 * The permissions a role grants (FR-39). Read and replaced as one value;
 * changes take effect immediately for every staff member holding the role.
 */
class RolePermissionController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
    ) {}

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    public function update(SyncRolePermissionsRequest $request, Role $role): RoleResource
    {
        $role = $this->roles->syncPermissions(
            $role,
            $request->validated('permissions'),
            $request->user(),
        );

        return new RoleResource($role->loadCount('users'));
    }
}
