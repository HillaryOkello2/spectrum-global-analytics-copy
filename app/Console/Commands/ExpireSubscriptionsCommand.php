<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;

class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Expire active subscriptions whose monthly term has lapsed';

    public function handle(): int
    {
        $expired = Subscription::lapsed()->update([
            'status' => SubscriptionStatus::Expired,
        ]);

        $this->info("Expired {$expired} subscription(s).");

        return self::SUCCESS;
    }
}
