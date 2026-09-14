<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Access\StaffAccountService;
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
        private readonly StaffAccountService $accounts,
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

    /**
     * Create a staff account. The password is generated, not chosen by the
     * admin, and emailed to the new user with their sign-in details.
     *
     * `meta.accountEmailSent` reports whether that email went out. When it is
     * false the account still exists, and `meta.temporaryPassword` carries the
     * generated password, this once, for the admin to pass on some other way.
     */
    public function store(StoreUserRequest $request): UserResource
    {
        $account = $this->accounts->create(
            $request->safe()->except('roles'),
            $request->validated('roles'),
            $request->user(),
        );

        $meta = ['accountEmailSent' => $account->emailed];

        if ($account->temporaryPassword !== null) {
            $meta['temporaryPassword'] = $account->temporaryPassword;
        }

        return (new UserResource($account->user))->additional(['meta' => $meta]);
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
