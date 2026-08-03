<?php

use App\Jobs\DispatchDueTopicsJob;
use Illuminate\Support\Facades\Schedule;

// Generation scheduler tick (§16.2): one daily run evaluates Daily/Weekly/
// Monthly/Quarterly due-ness per Topic; the job fans out to the llm queue.
Schedule::job(new DispatchDueTopicsJob)->dailyAt('00:05');

// Monthly billing terms: lapse overdue subscriptions (confirmed monthly billing).
Schedule::command('subscriptions:expire')->dailyAt('00:30');

// FIFO release (§6): approved products go live oldest-approval-first, a fixed
// batch per run, rather than the instant a proofreader signs one off.
Schedule::command('products:release')->cron(config('publishing.release_cron'));
