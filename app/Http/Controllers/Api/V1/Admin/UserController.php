<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Admin Portal
 *
 * Admin/staff user & role management (FR-39).
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserAccessService $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->staff()
            ->with(['roles.permissions', 'permissions'])
            ->filter($request->only(['search', 'status']))
            ->latest()
            ->paginate(20);

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $user = User::create([
            ...$request->safe()->except('roles'),
            'status' => UserStatus::Active,
        ]);

        activity()->causedBy($request->user())->performedOn($user)->log('admin user created');

        return new UserResource(
            $this->access->syncRoles($user, $request->validated('roles'), $request->user()),
        );
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load(['roles.permissions', 'permissions']));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update($request->safe()->except('roles'));

        activity()->causedBy($request->user())->performedOn($user)->log('admin user updated');

        if ($request->has('roles')) {
            $user = $this->access->syncRoles($user, $request->validated('roles'), $request->user());
        }

        return new UserResource($user->fresh());
    }
}
