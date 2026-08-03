<?php

namespace App\Http\Controllers\Api\V1\Admin\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncUserRolesRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Access\UserAccessService;

/**
 * @group Admin Portal
 *
 * A single user's role assignment (FR-39). Treated as a singleton sub-resource:
 * the roles of a user are read and replaced as one value.
 */
class UserRoleController extends Controller
{
    public function __construct(
        private readonly UserAccessService $access,
    ) {}

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(SyncUserRolesRequest $request, User $user): UserResource
    {
        return new UserResource(
            $this->access->syncRoles($user, $request->validated('roles'), $request->user()),
        );
    }
}
