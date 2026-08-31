<?php

namespace App\Jobs;

use App\Models\Component;
use App\Models\Topic;
use App\Services\Generation\GenerationPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler tick (§16.2), in two passes.
 *
 * 1. Recurring components (daily brief, weekly highlights, monthly focus) get
 *    this edition's topic commissioned from their static topic_prompt — the
 *    unattended path.
 * 2. Existing active topics due per their own frequency are queued for
 *    generation, which is how hand-filed long-form topics have always worked.
 *
 * The tick runs daily; a weekly or monthly component simply isn't due most days.
 */
class DispatchDueTopicsJob implements ShouldQueue
{
    use Queueable;

    public function handle(GenerationPipeline $pipeline): void
    {
        $commissioned = $this->commissionRecurringTopics();
        $dispatched = $this->dispatchDueTopics($pipeline);

        Log::info('Scheduled generation run completed.', [
            'topics_commissioned' => $commissioned,
            'topics_dispatched' => $dispatched,
        ]);
    }

    /**
     * Dispatching GenerateTopicJob is enough: it creates the topic and queues
     * generation itself, so a commissioned topic is deliberately NOT picked up
     * again by the second pass in the same run.
     */
    private function commissionRecurringTopics(): int
    {
        $commissioned = 0;

        Component::query()
            ->whereNotNull('generation_frequency')
            ->whereNotNull('topic_prompt')
            ->whereNotNull('prompt_template')
            ->each(function (Component $component) use (&$commissioned): void {
                if (! $this->isEditionDue($component)) {
                    return;
                }

                GenerateTopicJob::dispatch($component);
                $commissioned++;
            });

        return $commissioned;
    }

    /**
     * Due-ness is measured from the last topic this component commissioned, not
     * from when that topic last generated — an edition is "filed" once.
     */
    private function isEditionDue(Component $component): bool
    {
        $latest = $component->autoTopics()->latest('created_at')->first();

        return $component->generation_frequency->isDue($latest?->created_at, now());
    }

    private function dispatchDueTopics(GenerationPipeline $pipeline): int
    {
        $dispatched = 0;

        Topic::query()
            ->active()
            // Manual topics only. An auto topic is commissioned and fired once by
            // the first pass; leaving it in scope here would re-generate every
            // previous edition on the next tick.
            ->where('source', Topic::SOURCE_MANUAL)
            ->with('component')
            ->chunkById(100, function ($topics) use ($pipeline, &$dispatched): void {
                foreach ($topics as $topic) {
                    if ($topic->frequency->isDue($topic->last_generated_at, now())) {
                        $pipeline->queueTopic($topic);
                        $dispatched++;
                    }
                }
            });

        return $dispatched;
    }
}
