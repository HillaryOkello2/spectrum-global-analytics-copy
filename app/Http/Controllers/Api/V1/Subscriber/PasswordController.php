<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ChangePasswordRequest;
use Illuminate\Http\JsonResponse;

/**
 * @group Subscriber Portal
 */
class PasswordController extends Controller
{
    public function update(ChangePasswordRequest $request): JsonResponse
    {
        $request->user()->update(['password' => $request->validated('password')]);

        // Rotate tokens on password change; keep only the current session.
        $request->user()->tokens()
            ->whereKeyNot($request->user()->currentAccessToken()->id)
            ->delete();

        return response()->json(['message' => 'Password updated.']);
    }
}
