<?php

namespace App\Services\Billing;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\InvalidTierChangeException;
use App\Exceptions\Domain\SubscriptionNotActiveException;
use App\Exceptions\Domain\TierNotPurchasableException;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Notifications\SubscriptionDowngradeScheduled;
use App\Services\Billing\DTOs\SubscriptionChangeResult;
use App\Services\Payments\PaymentInitiator;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;

class SubscriptionChangeService
{
    public function __construct(
        private readonly PaymentInitiator $payments,
    ) {}

    /**
     * Renew the user's current subscription for another month.
     *
     * Normally that is the same tier. Where a downgrade has been scheduled, the
     * renewal is the term that carries it: it is priced against the cheaper
     * tier and lands on it, which is what "takes effect at the next billing"
     * means in practice.
     *
     * Free (Freemium) tiers extend immediately; paid tiers go through the
     * gateway.
     */
    public function renew(User $user, ?PaymentMethod $method = null): SubscriptionChangeResult
    {
        $subscription = $this->currentSubscription($user);
        $tier = $subscription->nextTier() ?? $subscription->tier;

        if ($tier->isFree()) {
            return $this->extendFreeImmediately($subscription, $tier);
        }

        return $this->initiatePaidChange(
            user: $user,
            subscription: $subscription,
            tier: $tier,
            // A scheduled downgrade is paid for as that tier, exactly as an
            // upgrade is, so the gateway callback can tell which tier the next
            // term runs on. A plain renewal stays payable against the
            // subscription and keeps its existing tier.
            payable: $subscription->hasPendingDowngrade() ? $tier : $subscription,
            method: $method ?? PaymentMethod::Mpesa,
        );
    }

    /**
     * Upgrade to a higher tier, charging the full price of the new tier. The
     * change takes effect (fresh monthly term) once payment clears. Upgrades are
     * always paid, since the target must be priced above the current tier.
     *
     * Paying for an upgrade also cancels any downgrade that was scheduled —
     * see SubscriptionActivator::applyTierChange().
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

    /**
     * Schedule a move to a cheaper tier, effective when the current term ends.
     *
     * Nothing is charged and nothing changes today. Dropping a subscriber to a
     * cheaper tier the moment they ask would take away access they have already
     * paid for to the end of the month, so the target is parked on the
     * subscription and applied when the term rolls over: on renewal, or — when
     * the target is free — at expiry, since a free tier needs no payment to
     * start.
     *
     * Choosing the tier they are already on, while a downgrade is pending,
     * means "never mind": the frontend offers one tier picker, and picking your
     * current tier in it is how a subscriber cancels a scheduled change.
     */
    public function downgrade(User $user, SubscriptionTier $target): SubscriptionChangeResult
    {
        $subscription = $this->currentSubscription($user);
        $current = $subscription->tier;

        if ($target->id === $current->id) {
            if ($subscription->hasPendingDowngrade()) {
                return $this->cancelDowngrade($user);
            }

            throw new InvalidTierChangeException('You are already on this tier.');
        }

        if (! $target->is_active) {
            throw new TierNotPurchasableException;
        }

        if ((float) $target->price >= (float) $current->price) {
            throw new InvalidTierChangeException('A downgrade must target a lower-priced tier. Use upgrade instead.');
        }

        // A term that has not started, or has already closed, has no end date
        // to schedule against — there is nothing to wait for, so such an
        // account changes tier by subscribing to the one it wants.
        if ($subscription->status !== SubscriptionStatus::Active || $subscription->ends_at === null) {
            throw new SubscriptionNotActiveException(
                'Only an active subscription can be downgraded. Subscribe to the tier you want instead.',
            );
        }

        $before = $subscription->pendingTier()->first()?->name;

        $subscription->update(['pending_tier_id' => $target->id]);

        Audit::of($subscription)->by($user)
            ->change('pendingTier', $before, $target->name)
            ->with([
                'currentTier' => $current->name,
                'effectiveAt' => $subscription->ends_at?->toDateString(),
            ])
            ->log('downgrade scheduled for end of term');

        $user->notify(new SubscriptionDowngradeScheduled($subscription->fresh(), $target));

        return new SubscriptionChangeResult(
            subscription: $subscription->refresh()->load(['tier', 'pendingTier']),
        );
    }

    /**
     * Call off a scheduled downgrade, leaving the subscription on its current
     * tier. A no-op (rather than an error) when none is scheduled: the
     * subscriber's intent — "stay where I am" — is already satisfied.
     */
    public function cancelDowngrade(User $user): SubscriptionChangeResult
    {
        $subscription = $this->currentSubscription($user);

        if ($subscription->hasPendingDowngrade()) {
            $before = $subscription->pendingTier()->first()?->name;

            $subscription->update(['pending_tier_id' => null]);

            Audit::of($subscription)->by($user)
                ->change('pendingTier', $before, null)
                ->log('scheduled downgrade cancelled');
        }

        return new SubscriptionChangeResult(
            subscription: $subscription->refresh()->load(['tier', 'pendingTier']),
        );
    }

    /**
     * Apply a scheduled downgrade whose term has now ended, without a payment.
     *
     * Only ever called for a free target (see ExpireSubscriptionsCommand): a
     * paid tier has to be paid for, so that one waits for the renewal instead
     * and the subscription expires in the meantime.
     */
    public function applyScheduledDowngrade(Subscription $subscription): Subscription
    {
        $target = $subscription->pendingTier()->first();

        if ($target === null || ! $target->isFree()) {
            throw new InvalidTierChangeException('No free tier is scheduled for this subscription.');
        }

        $before = $subscription->only(['status', 'ends_at']);
        $fromTier = $subscription->tier?->name;

        $subscription->update([
            'tier_id' => $target->id,
            'pending_tier_id' => null,
            'status' => SubscriptionStatus::Active,
            // A fresh term starts its expiry notices over.
            'last_reminder_days' => null,
            'ends_at' => now()->addMonth(),
        ]);

        Audit::of($subscription)->by($subscription->user)
            ->change('tier', $fromTier, $target->name)
            ->changes($before, $subscription->only(['status', 'ends_at']))
            ->log('scheduled downgrade applied at end of term');

        return $subscription->refresh();
    }

    private function currentSubscription(User $user): Subscription
    {
        $subscription = $user->subscriptions()->with(['tier', 'pendingTier'])->latest('id')->first();

        if ($subscription === null) {
            throw new SubscriptionNotActiveException('No subscription found to change.');
        }

        return $subscription;
    }

    /**
     * Extend a free term by a month. `$tier` is normally the one it is already
     * on; it differs when a downgrade onto a free tier is being renewed into,
     * in which case this is also the moment the tier changes.
     */
    private function extendFreeImmediately(Subscription $subscription, SubscriptionTier $tier): SubscriptionChangeResult
    {
        $base = $subscription->ends_at?->isFuture() ? $subscription->ends_at : now();
        $before = $subscription->only(['tier_id', 'status', 'starts_at', 'ends_at']);
        $fromTier = $subscription->tier?->name;

        $subscription->update([
            'tier_id' => $tier->id,
            'pending_tier_id' => null,
            'status' => SubscriptionStatus::Active,
            'starts_at' => $subscription->starts_at ?? now(),
            'ends_at' => $base->copy()->addMonth(),
            'last_reminder_days' => null,
        ]);

        $audit = Audit::of($subscription)->by($subscription->user)
            ->changes($before, $subscription->only(['tier_id', 'status', 'starts_at', 'ends_at']));

        if ($fromTier !== $tier->name) {
            $audit->change('tier', $fromTier, $tier->name);
        }

        $audit->log($fromTier === $tier->name
            ? 'freemium subscription renewed'
            : 'scheduled downgrade applied on renewal');

        return new SubscriptionChangeResult(subscription: $subscription->refresh()->load('tier'));
    }

    /**
     * Raise a pending payment for a renewal (payable = the subscription) or a
     * tier change (payable = the target tier), then hand off to the gateway.
     * The callback's SubscriptionActivator applies the change on success.
     */
    private function initiatePaidChange(
        User $user,
        Subscription $subscription,
        SubscriptionTier $tier,
        Model $payable,
        PaymentMethod $method,
    ): SubscriptionChangeResult {
        $started = $this->payments->start($user, $subscription, $payable, $tier, $method);

        return new SubscriptionChangeResult(
            subscription: $subscription,
            payment: $started->payment,
            paymentInitiation: $started->initiation,
        );
    }
}
