<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriber\UpdateNotificationPreferencesRequest;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\JsonResponse;

/**
 * @group Subscriber Portal
 *
 * How often (and for which components) the subscriber wants new-article emails.
 */
class NotificationPreferencesController extends Controller
{
    public function __construct(
        private readonly NotificationPreferences $preferences,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->preferences->for(request()->user()),
        ]);
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->preferences->update($request->user(), $request->validated()),
        ]);
    }
}
