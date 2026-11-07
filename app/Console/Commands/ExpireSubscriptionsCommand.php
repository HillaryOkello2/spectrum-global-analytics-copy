<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Notifications\SubscriptionEnded;
use App\Services\Billing\SubscriptionChangeService;
use Illuminate\Console\Command;

class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Roll lapsed paid terms onto Freemium (or a scheduled free tier); expire only when a paid downgrade is still unpaid';

    public function handle(SubscriptionChangeService $changes): int
    {
        $scheduled = $this->applyScheduledDowngrades($changes);
        $cleared = $this->clearLapsedFreeEnds();
        $rolled = $this->rollOntoDefaultFree($changes);
        $expired = $this->expirePaidPending();

        $this->info(
            "Scheduled free downgrades: {$scheduled}; "
            ."cleared free ends_at: {$cleared}; "
            ."moved to Freemium: {$rolled}; "
            ."expired (paid pending): {$expired}."
        );

        return self::SUCCESS;
    }

    /**
     * A term that has ended with a downgrade to a *free* tier waiting on it
     * does not expire — it rolls onto that tier, which is what the subscriber
     * asked for and what makes "downgrade to Freemium" the way to step off a
     * paid plan without losing the account.
     *
     * A downgrade to another *paid* tier is left alone: it still has to be paid
     * for, so the subscription expires here as any other does, and the pending
     * tier is applied by whichever renewal eventually follows.
     */
    private function applyScheduledDowngrades(SubscriptionChangeService $changes): int
    {
        $applied = 0;

        Subscription::lapsedOntoFreeTier()
            ->with(['user', 'tier', 'pendingTier'])
            ->chunkById(100, function ($subscriptions) use ($changes, &$applied): void {
                foreach ($subscriptions as $subscription) {
                    $changes->applyScheduledDowngrade($subscription);
                    $applied++;
                }
            });

        return $applied;
    }

    /**
     * Freemium (and any other free tier) must never lapse into "no access".
     * Older rows may still carry an ends_at; clear it when the term has passed.
     */
    private function clearLapsedFreeEnds(): int
    {
        $cleared = 0;

        Subscription::lapsed()
            ->whereHas('tier', fn ($tier) => $tier->where('price', 0)->orWhere('is_default_free', true))
            ->chunkById(100, function ($subscriptions) use (&$cleared): void {
                foreach ($subscriptions as $subscription) {
                    $subscription->update(['ends_at' => null, 'last_reminder_days' => null]);
                    $cleared++;
                }
            });

        return $cleared;
    }

    /**
     * Paid term ended with nothing scheduled (or only a free path already
     * handled): move onto the default free tier and keep the account active.
     */
    private function rollOntoDefaultFree(SubscriptionChangeService $changes): int
    {
        if (SubscriptionTier::defaultFree() === null) {
            $this->warn('No default free tier configured; skipping Freemium rollover.');

            return 0;
        }

        $rolled = 0;

        // No pending paid target: null pending, or pending already applied above.
        Subscription::lapsed()
            ->where(function ($query): void {
                $query->whereNull('pending_tier_id')
                    ->orWhereHas('pendingTier', fn ($tier) => $tier->where('price', 0));
            })
            ->with(['user', 'tier'])
            ->chunkById(100, function ($subscriptions) use ($changes, &$rolled): void {
                foreach ($subscriptions as $subscription) {
                    // Still on a free tier after clearLapsedFreeEnds — skip.
                    if ($subscription->tier?->isFree()) {
                        continue;
                    }

                    $updated = $changes->lapseOntoDefaultFree($subscription);
                    $updated->user?->notify(new SubscriptionEnded($updated));
                    $rolled++;
                }
            });

        return $rolled;
    }

    /**
     * A scheduled downgrade to another *paid* tier still needs payment, so the
     * subscription expires until the subscriber renews into that pending tier.
     */
    private function expirePaidPending(): int
    {
        $expired = 0;

        Subscription::lapsed()
            ->whereHas('pendingTier', fn ($tier) => $tier->where('price', '>', 0))
            ->with(['user', 'tier'])
            ->chunkById(100, function ($subscriptions) use (&$expired): void {
                foreach ($subscriptions as $subscription) {
                    $subscription->update(['status' => SubscriptionStatus::Expired]);
                    $subscription->user?->notify(new SubscriptionEnded($subscription->fresh(['tier'])));
                    $expired++;
                }
            });

        return $expired;
    }
}
