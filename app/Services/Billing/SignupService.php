<?php

namespace App\Services\Billing;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Exceptions\Domain\TierNotPurchasableException;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Billing\DTOs\SignupResult;
use App\Services\Payments\PaymentInitiator;
use Illuminate\Support\Facades\DB;

class SignupService
{
    public function __construct(
        private readonly PaymentInitiator $payments,
    ) {}

    /**
     * Register a user on a tier (§17.1, §18.1).
     *
     * Freemium (price 0): user + subscription activate immediately, no gateway
     * involvement, an auth token is returned (confirmed business rule).
     * Paid tiers: user/subscription/payment stay pending until the gateway
     * callback confirms payment.
     *
     * @param array{first_name: string, last_name: string, email: string, phone: string,
     *              country: string, password: string} $data
     */
    public function register(array $data, SubscriptionTier $tier, ?PaymentMethod $method = null): SignupResult
    {
        if (! $tier->is_active) {
            throw new TierNotPurchasableException;
        }

        return $tier->isFree()
            ? $this->registerFreemium($data, $tier)
            : $this->registerPaid($data, $tier, $method ?? PaymentMethod::Mpesa);
    }

    private function registerFreemium(array $data, SubscriptionTier $tier): SignupResult
    {
        [$user, $subscription] = DB::transaction(function () use ($data, $tier) {
            $user = User::create([...$data, 'status' => UserStatus::Active]);
            $user->assignRole('subscriber');

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'tier_id' => $tier->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
            ]);

            return [$user, $subscription];
        });

        activity()->causedBy($user)->performedOn($subscription)->log('freemium signup activated');

        return new SignupResult(
            user: $user,
            subscription: $subscription,
            token: $user->createToken('subscriber')->plainTextToken,
        );
    }

    private function registerPaid(array $data, SubscriptionTier $tier, PaymentMethod $method): SignupResult
    {
        [$user, $subscription, $payment] = DB::transaction(function () use ($data, $tier, $method) {
            $user = User::create([...$data, 'status' => UserStatus::Pending]);
            $user->assignRole('subscriber');

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'tier_id' => $tier->id,
                'status' => SubscriptionStatus::Pending,
            ]);

            $payment = $this->payments->raise($user, $subscription, $subscription, $tier, $method);

            return [$user, $subscription, $payment];
        });

        // Only after the commit: see PaymentInitiator::send().
        $initiation = $this->payments->send($payment);

        return new SignupResult(
            user: $user,
            subscription: $subscription,
            payment: $payment,
            paymentInitiation: $initiation,
        );
    }
}
