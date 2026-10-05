<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Notifications\SubscriptionEndingSoon;
use Illuminate\Console\Command;

/**
 * Warns subscribers before a term lapses, twice: a week out and on the last
 * day. `last_reminder_days` records which notice has been sent, so a daily run
 * cannot send the same one seven times, and a renewal clears it.
 */
class RemindExpiringSubscriptionsCommand extends Command
{
    private const FIRST_NOTICE_DAYS = 7;

    private const FINAL_NOTICE_DAYS = 1;

    protected $signature = 'subscriptions:remind';

    protected $description = 'Warn subscribers whose term is about to lapse';

    public function handle(): int
    {
        $sent = 0;

        Subscription::query()
            ->active()
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays(self::FIRST_NOTICE_DAYS)])
            ->with(['user', 'tier'])
            ->chunkById(100, function ($subscriptions) use (&$sent): void {
                foreach ($subscriptions as $subscription) {
                    $daysLeft = max(0, (int) ceil(now()->diffInDays($subscription->ends_at, absolute: false)));
                    $stage = $daysLeft <= self::FINAL_NOTICE_DAYS ? self::FINAL_NOTICE_DAYS : self::FIRST_NOTICE_DAYS;

                    // Already had this notice, or a later one.
                    if ($subscription->last_reminder_days !== null && $subscription->last_reminder_days <= $stage) {
                        continue;
                    }

                    $subscription->user?->notify(new SubscriptionEndingSoon($subscription, $daysLeft));
                    $subscription->update(['last_reminder_days' => $stage]);
                    $sent++;
                }
            });

        $this->info("Sent {$sent} expiry reminder(s).");

        return self::SUCCESS;
    }
}
