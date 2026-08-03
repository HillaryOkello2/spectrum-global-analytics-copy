<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSubscriberRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
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
        $subscriber->update($request->validated());

        activity()->causedBy($request->user())->performedOn($subscriber)->log('subscriber updated');

        return new UserResource($subscriber->fresh()->load('activeSubscription.tier'));
    }
}
