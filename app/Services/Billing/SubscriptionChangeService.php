<?php

namespace App\Services\Billing;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\InvalidTierChangeException;
use App\Exceptions\Domain\SubscriptionNotActiveException;
use App\Exceptions\Domain\TierNotPurchasableException;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Billing\DTOs\SubscriptionChangeResult;
use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionChangeService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * Renew the user's current subscription for another month on the same tier.
     * Free (Freemium) tiers extend immediately; paid tiers go through the gateway.
     */
    public function renew(User $user, ?PaymentMethod $method = null): SubscriptionChangeResult
    {
        $subscription = $this->currentSubscription($user);
        $tier = $subscription->tier;

        if ($tier->isFree()) {
            return $this->extendFreeImmediately($subscription);
        }

        return $this->initiatePaidChange(
            user: $user,
            subscription: $subscription,
            tier: $tier,
            payable: $subscription,
            method: $method ?? PaymentMethod::Mpesa,
        );
    }

    /**
     * Upgrade to a higher tier, charging the full price of the new tier. The
     * change takes effect (fresh monthly term) once payment clears. Upgrades are
     * always paid, since the target must be priced above the current tier.
     */
    public function upgrade(User $user, SubscriptionTier $target, ?PaymentMethod $method = null): SubscriptionChangeResult
    {
        $subscription = $this->currentSubscription($user);
        $current = $subscription->tier;

        if (! $target->is_active) {
            throw new TierNotPurchasableException;
        }

        if ($target->id === $current->id) {
            throw new InvalidTierChangeException('You are already on this tier. Use renew instead.');
        }

        if ((float) $target->price <= (float) $current->price) {
            throw new InvalidTierChangeException('An upgrade must target a higher-priced tier.');
        }

        return $this->initiatePaidChange(
            user: $user,
            subscription: $subscription,
            tier: $target,
            payable: $target,
            method: $method ?? PaymentMethod::Mpesa,
        );
    }

    private function currentSubscription(User $user): Subscription
    {
        $subscription = $user->subscriptions()->with('tier')->latest('id')->first();

        if ($subscription === null) {
            throw new SubscriptionNotActiveException('No subscription found to change.');
        }

        return $subscription;
    }

    private function extendFreeImmediately(Subscription $subscription): SubscriptionChangeResult
    {
        $base = $subscription->ends_at?->isFuture() ? $subscription->ends_at : now();

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'starts_at' => $subscription->starts_at ?? now(),
            'ends_at' => $base->copy()->addMonth(),
        ]);

        activity()->causedBy($subscription->user)->performedOn($subscription)->log('freemium subscription renewed');

        return new SubscriptionChangeResult(subscription: $subscription->refresh());
    }

    /**
     * Raise a pending payment for a renewal (payable = the subscription) or an
     * upgrade (payable = the target tier), then hand off to the gateway. The
     * callback's SubscriptionActivator applies the change on success.
     */
    private function initiatePaidChange(
        User $user,
        Subscription $subscription,
        SubscriptionTier $tier,
        Model $payable,
        PaymentMethod $method,
    ): SubscriptionChangeResult {
        $payment = DB::transaction(fn (): Payment => $subscription->payments()->create([
            'user_id' => $user->id,
            'payable_type' => $payable::class,
            'payable_id' => $payable->id,
            'method' => $method,
            'amount' => $tier->price,
            'currency' => $tier->currency,
            'status' => PaymentStatus::Pending,
            'gateway' => config('payments.gateway'),
            'idempotency_key' => (string) Str::uuid(),
        ]));

        $initiation = $this->gateway->initiate($payment);
        $payment->update(['gateway_ref' => $initiation->gatewayRef]);

        return new SubscriptionChangeResult(
            subscription: $subscription,
            payment: $payment,
            paymentInitiation: $initiation,
        );
    }
}
