<?php

namespace App\Console\Commands;

use App\Services\Notifications\NewArticleNotifier;
use Illuminate\Console\Command;

class SendArticleDigestsCommand extends Command
{
    protected $signature = 'articles:send-digests';

    protected $description = 'Send daily new-article digests queued for subscribers who chose daily delivery';

    public function handle(NewArticleNotifier $notifier): int
    {
        $sent = $notifier->sendDigests();

        $this->info("Sent {$sent} article digest(s).");

        return self::SUCCESS;
    }
}
