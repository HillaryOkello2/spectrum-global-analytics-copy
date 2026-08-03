<?php

namespace App\Http\Controllers\Api\V1\Public\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * @group Authentication
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * Login flow (§18.2): invalid credentials → 422; valid but pending →
     * complete-payment message; suspended → denied; active → token + portal.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if ($user === null || ! Auth::validate($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if ($user->status === UserStatus::Pending) {
            return response()->json([
                'message' => 'Access denied — complete your subscription payment to activate your account.',
                'code' => 'payment_pending',
            ], 403);
        }

        if ($user->status === UserStatus::Suspended) {
            return response()->json([
                'message' => 'Your account has been suspended.',
                'code' => 'account_suspended',
            ], 403);
        }

        return response()->json([
            'data' => [
                'user' => new UserResource($user->load('activeSubscription.tier')),
                'token' => $user->createToken($user->portal())->plainTextToken,
                'portal' => $user->portal(),
            ],
        ]);
    }

    /**
     * Current session ("who am I") — works for ANY authenticated user, unlike
     * GET /me which is subscriber-only. The frontend calls this on page refresh
     * to rehydrate the user and decide which portal to route into.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => new UserResource($user->load('activeSubscription.tier')),
                'portal' => $user->portal(),
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
