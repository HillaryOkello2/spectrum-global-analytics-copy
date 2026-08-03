<?php

namespace App\Http\Controllers\Api\V1\Admin\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncUserPermissionsRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Access\UserAccessService;

/**
 * @group Admin Portal
 *
 * A single user's direct permissions — extras granted on top of their roles
 * (FR-39). Read and replaced as one value.
 */
class UserPermissionController extends Controller
{
    public function __construct(
        private readonly UserAccessService $access,
    ) {}

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(SyncUserPermissionsRequest $request, User $user): UserResource
    {
        return new UserResource(
            $this->access->syncPermissions($user, $request->validated('permissions'), $request->user()),
        );
    }
}
