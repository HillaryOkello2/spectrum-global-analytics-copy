<?php

namespace App\Jobs;

use App\Models\Component;
use App\Services\Generation\GenerationPipeline;
use App\Services\Generation\TopicGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 0 for the recurring components: commission this edition's topic, then
 * hand straight over to the normal generation pipeline.
 *
 * Only components with a `generation_frequency` reach here — the long-form ones
 * still get their topics filed by an admin.
 */
class GenerateTopicJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public Component $component,
    ) {
        $this->onQueue($component->queue_name);
    }

    public function handle(TopicGenerator $generator, GenerationPipeline $pipeline): void
    {
        $topic = $generator->generate($this->component);

        $pipeline->queueTopic($topic);
    }

    /**
     * There is no task row yet at this stage, so a failure has nowhere to surface
     * on the queue dashboard — log it loudly instead. The next scheduler tick
     * will find the edition still due and try again.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Topic generation failed.', [
            'component' => $this->component->code,
            'error' => $exception?->getMessage() ?? 'Unknown topic generation failure.',
        ]);
    }
}
