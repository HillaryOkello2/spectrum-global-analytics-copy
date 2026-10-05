<?php

namespace App\Jobs;

use App\Jobs\Middleware\FailOnTokenCeiling;
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

    /**
     * Longer than the HTTP call it waits on, or the worker kills itself
     * mid-generation: Laravel SIGKILLs a job that outruns its timeout, which
     * surfaces as exit 137 and spends the model credit for nothing.
     */
    public int $timeout;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public Component $component,
    ) {
        $this->onQueue($component->queue_name);

        // Two minutes beyond the HTTP timeout it is waiting on.
        $this->timeout = (int) config('llm.timeout') + 120;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new FailOnTokenCeiling];
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
