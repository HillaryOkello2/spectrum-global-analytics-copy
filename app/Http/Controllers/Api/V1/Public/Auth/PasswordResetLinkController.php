<?php

namespace App\Http\Controllers\Api\V1\Public\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * @group Authentication
 */
class PasswordResetLinkController extends Controller
{
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        // A mail outage must not change the answer. Mail is only attempted for
        // a registered address, so if it threw, a registered address would get
        // a 500 and an unknown one a 200 — telling anyone probing the endpoint
        // which emails have accounts. Report it and respond as usual.
        try {
            Password::sendResetLink($request->validated());
        } catch (Throwable $e) {
            report($e);
        }

        // Uniform response — never reveal whether an email exists.
        return response()->json([
            'message' => 'If that email address is registered, a reset link has been sent.',
        ]);
    }
}
