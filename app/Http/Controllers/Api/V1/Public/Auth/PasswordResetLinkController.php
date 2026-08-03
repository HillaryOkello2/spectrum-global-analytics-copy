<?php

namespace App\Http\Controllers\Api\V1\Public\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

/**
 * @group Authentication
 */
class PasswordResetLinkController extends Controller
{
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->validated());

        // Uniform response — never reveal whether an email exists.
        return response()->json([
            'message' => 'If that email address is registered, a reset link has been sent.',
        ]);
    }
}
