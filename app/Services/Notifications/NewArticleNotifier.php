<?php

namespace App\Services\Notifications;

use App\Enums\AccessType;
use App\Enums\SubscriptionStatus;
use App\Models\ArticleDigestQueue;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\NewArticlePublished;
use Illuminate\Support\Facades\Notification;

/**
 * Emails eligible subscribers when a product is published for the first time.
 */
class NewArticleNotifier
{
    public function __construct(
        private readonly NotificationPreferences $preferences,
    ) {}

    public function notify(Product $product): void
    {
        $product->loadMissing('component');
        $code = $product->component?->code;

        if ($code === null || $product->component?->is_virtual) {
            return;
        }

        Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->with(['user', 'tier.allocations.component'])
            ->chunkById(100, function ($subscriptions) use ($product, $code): void {
                foreach ($subscriptions as $subscription) {
                    $user = $subscription->user;
                    if ($user === null) {
                        continue;
                    }

                    if (! $this->tierAllows($subscription, $code)) {
                        continue;
                    }

                    $prefs = $this->preferences->for($user);
                    if (! $this->preferences->wantsComponent($prefs, $code)) {
                        continue;
                    }

                    match ($prefs['newArticles']) {
                        NotificationPreferences::EACH => $user->notify(new NewArticlePublished($product)),
                        NotificationPreferences::DAILY => ArticleDigestQueue::query()->firstOrCreate([
                            'user_id' => $user->id,
                            'product_id' => $product->id,
                        ]),
                        default => null,
                    };
                }
            });
    }

    /**
     * Send one digest per user with queued items, then clear the queue.
     */
    public function sendDigests(): int
    {
        $sent = 0;

        ArticleDigestQueue::query()
            ->select('user_id')
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->each(function (int $userId) use (&$sent): void {
                $user = User::find($userId);
                if ($user === null) {
                    ArticleDigestQueue::query()->where('user_id', $userId)->delete();

                    return;
                }

                $prefs = $this->preferences->for($user);
                if ($prefs['newArticles'] !== NotificationPreferences::DAILY) {
                    ArticleDigestQueue::query()->where('user_id', $userId)->delete();

                    return;
                }

                $items = ArticleDigestQueue::query()
                    ->where('user_id', $userId)
                    ->with(['product.component'])
                    ->get();

                $products = $items->map->product->filter()->values();
                if ($products->isEmpty()) {
                    $items->each->delete();

                    return;
                }

                Notification::send($user, new NewArticlePublished($products->all(), digest: true));
                ArticleDigestQueue::query()->where('user_id', $userId)->delete();
                $sent++;
            });

        return $sent;
    }

    private function tierAllows(Subscription $subscription, string $componentCode): bool
    {
        $allocation = $subscription->tier?->allocations
            ->first(fn ($row) => $row->component?->code === $componentCode);

        if ($allocation === null) {
            return false;
        }

        return $allocation->access_type !== AccessType::Denied;
    }
}
