<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Services\Access\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Permission\Models\Role;

/**
 * @group Admin Portal
 *
 * Staff roles and what each one grants (FR-39). Admins create their own roles
 * here — e.g. a Proofreader who may only work the task board — and assign staff
 * to them. The `subscriber` role is excluded: subscriber accounts come from
 * registration and are managed under /admin/subscribers.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        // loadCount, not withCount: spatie resolves Role::users() from the
        // instance's guard_name, which a bare query-builder instance lacks.
        return RoleResource::collection(
            Role::whereNot('name', User::SUBSCRIBER)
                ->with('permissions')
                ->orderBy('name')
                ->get()
                ->loadCount('users'),
        );
    }

    public function store(StoreRoleRequest $request): RoleResource
    {
        $role = $this->roles->create(
            $request->validated('name'),
            $request->validated('permissions'),
            $request->user(),
        );

        return new RoleResource($role->loadCount('users'));
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $role = $this->roles->rename($role, $request->validated('name'), $request->user());

        return new RoleResource($role->loadCount('users'));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->roles->delete($role, $request->user());

        return response()->json(['message' => 'Role deleted.']);
    }
}
