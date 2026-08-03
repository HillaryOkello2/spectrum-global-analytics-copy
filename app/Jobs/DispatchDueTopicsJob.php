<?php

namespace App\Jobs;

use App\Models\Topic;
use App\Services\Generation\GenerationPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler tick (§16.2): finds active Topics due per their frequency and queues
 * a generation task for each.
 */
class DispatchDueTopicsJob implements ShouldQueue
{
    use Queueable;

    public function handle(GenerationPipeline $pipeline): void
    {
        $dispatched = 0;

        Topic::query()
            ->active()
            ->with('component')
            ->chunkById(100, function ($topics) use ($pipeline, &$dispatched): void {
                foreach ($topics as $topic) {
                    if ($topic->frequency->isDue($topic->last_generated_at, now())) {
                        $pipeline->queueTopic($topic);
                        $dispatched++;
                    }
                }
            });

        Log::info('Scheduled generation run completed.', ['topics_dispatched' => $dispatched]);
    }
}
