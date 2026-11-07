<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Notifications\NewArticleNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NotifySubscribersOfNewArticleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Product $product,
    ) {}

    public function handle(NewArticleNotifier $notifier): void
    {
        $notifier->notify($this->product);
    }
}
