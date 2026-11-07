<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signed link from new-article emails — sets newArticles to "none" without a login.
 *
 * The route parameter is `{subscriber}` (not `{user}`): AppServiceProvider binds
 * `user` to staff only, which would 404 every subscriber unsubscribe link.
 */
class UnsubscribeArticleEmailsController extends Controller
{
    public function __invoke(Request $request, User $subscriber, NotificationPreferences $preferences): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return response()->json([
                'message' => 'This unsubscribe link is invalid or has expired.',
                'code' => 'invalid_signature',
            ], 403);
        }

        $preferences->unsubscribe($subscriber);

        return response()->json([
            'message' => 'You will no longer receive new-article emails.',
            'data' => $preferences->for($subscriber),
        ]);
    }
}
