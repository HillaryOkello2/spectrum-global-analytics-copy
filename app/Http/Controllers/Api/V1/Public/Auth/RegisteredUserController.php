<?php

namespace App\Http\Controllers\Api\V1\Public\Auth;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\UserResource;
use App\Models\SubscriptionTier;
use App\Services\Billing\SignupService;
use Illuminate\Http\JsonResponse;

/**
 * @group Authentication
 */
class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly SignupService $signupService,
    ) {}

    /**
     * Sign up (FR-10/11, §18.1). Freemium activates immediately and returns a
     * token; paid tiers return payment instructions to complete via callback.
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $tier = SubscriptionTier::where('public_id', $request->validated('tier'))->firstOrFail();

        $method = $request->validated('payment_method')
            ? PaymentMethod::from($request->validated('payment_method'))
            : null;

        $result = $this->signupService->register(
            $request->safe()->only(['first_name', 'last_name', 'email', 'phone', 'country', 'password']),
            $tier,
            $method,
        );

        if (! $result->requiresPayment()) {
            return response()->json([
                'message' => 'Account activated.',
                'data' => [
                    'user' => new UserResource($result->user),
                    'token' => $result->token,
                ],
            ], 201);
        }

        return response()->json([
            'message' => 'Registration received. Complete payment to activate your account.',
            'data' => [
                'payment' => new PaymentResource($result->payment),
                'instructions' => $result->paymentInitiation->instructions,
            ],
        ], 201);
    }
}
