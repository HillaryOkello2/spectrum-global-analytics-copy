<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\SubscriptionEnded;
use Illuminate\Console\Command;

class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Expire active subscriptions whose monthly term has lapsed';

    public function handle(): int
    {
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

        $this->info("Expired {$expired} subscription(s).");

        return self::SUCCESS;
    }
}
