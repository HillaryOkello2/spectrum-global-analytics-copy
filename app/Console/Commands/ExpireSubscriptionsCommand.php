<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\SubscriptionEnded;
use App\Services\Billing\SubscriptionChangeService;
use Illuminate\Console\Command;

class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Expire active subscriptions whose monthly term has lapsed';

    public function handle(SubscriptionChangeService $changes): int
    {
        $downgraded = $this->applyScheduledDowngrades($changes);
        $expired = 0;

        // Row by row rather than one mass update: a term ending is the one
        // thing a subscriber should never discover by finding the door locked.
        Subscription::lapsed()->with(['user', 'tier'])->chunkById(100, function ($subscriptions) use (&$expired): void {
            foreach ($subscriptions as $subscription) {
                $subscription->update(['status' => SubscriptionStatus::Expired]);

                $subscription->user?->notify(new SubscriptionEnded($subscription));
                $expired++;
            }
        });

        $this->info("Moved {$downgraded} subscription(s) to a scheduled free tier, expired {$expired}.");

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
     *
     * Run before the expiry sweep, so these rows are no longer lapsed by the
     * time it looks.
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
}
