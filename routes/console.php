<?php

use App\Jobs\DispatchDueTopicsJob;
use Illuminate\Support\Facades\Schedule;

// Queue workers. The host runs no Supervisor, so they are scheduled rather
// than daemonised: each minute starts a worker that drains its queues and
// exits, which also means a deploy's new code is picked up on the next minute
// without a `queue:restart`. `withoutOverlapping` keeps exactly one of each.
//
// Two of them, because one worker serving both would let a 56-page research
// paper hold a payment receipt for ten minutes.
Schedule::command(
    'queue:work --queue='.config('queue.worker_queues.default').' --stop-when-empty --max-time=50 --tries=3'
)->everyMinute()->withoutOverlapping()->name('worker-default');

Schedule::command(
    'queue:work --queue='.config('queue.worker_queues.generation')
        .' --stop-when-empty --max-time=300 --memory=256'
        .' --timeout='.((int) config('llm.timeout') + 120)
)->everyMinute()->withoutOverlapping()->name('worker-generation');

// Generation scheduler tick (§16.2): one daily run evaluates Daily/Weekly/
// Monthly/Quarterly due-ness per Topic; the job fans out to the llm queue.
Schedule::job(new DispatchDueTopicsJob)->dailyAt('00:05');

// Monthly billing terms: lapse overdue subscriptions (confirmed monthly billing).
Schedule::command('subscriptions:expire')->dailyAt('00:30');

// Expiry notices a week out and on the last day, at an hour someone will read.
Schedule::command('subscriptions:remind')->dailyAt('08:00');

// PGW calls back on success only, so a declined or ignored M-Pesa prompt has to
// be timed out here before it can be retried.
Schedule::command('payments:expire-pending')->everyFiveMinutes();

// FIFO release (§6): approved products go live oldest-approval-first, a fixed
// batch per run, rather than the instant a proofreader signs one off.
Schedule::command('products:release')->cron(config('publishing.release_cron'));
