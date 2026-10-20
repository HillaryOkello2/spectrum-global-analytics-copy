<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSubscriberRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Admin Portal
 *
 * Subscriber management: searchable list with tier/status filters (FR-41, §14.12).
 */
class SubscriberController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $subscribers = User::query()
            ->subscriber()
            ->with('activeSubscription.tier')
            ->filter($request->only(['search', 'status', 'tier']))
            ->latest()
            ->paginate(20);

        return UserResource::collection($subscribers);
    }

    public function show(User $subscriber): UserResource
    {
        return new UserResource(
            $subscriber->load('activeSubscription.tier', 'subscriptions.tier'),
        );
    }

    public function update(UpdateSubscriberRequest $request, User $subscriber): UserResource
    {
        // Snapshot limited to the submitted fields, so the log says which of
        // them an admin actually changed rather than listing the whole row.
        $before = $subscriber->only(array_keys($request->validated()));

        $subscriber->update($request->validated());

        Audit::of($subscriber)->by($request->user())
            ->changes($before, $subscriber->only(array_keys($request->validated())))
            ->log('subscriber updated');

        return new UserResource($subscriber->fresh()->load('activeSubscription.tier'));
    }
}
