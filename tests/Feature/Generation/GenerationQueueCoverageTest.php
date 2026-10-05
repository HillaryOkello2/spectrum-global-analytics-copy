<?php

use App\Jobs\GenerateProductJob;
use App\Jobs\GenerateTopicJob;
use App\Jobs\RunQaPromptJob;
use App\Models\Component;
use App\Models\GenerationTask;
use Database\Seeders\ComponentSeeder;
use Database\Seeders\LlmProviderSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

it('serves every component queue with a worker', function (): void {
    // A component added without its queue being listed would generate nothing:
    // the jobs would sit in the table, unworked and unexplained.
    $this->seed(LlmProviderSeeder::class);
    $this->seed(ComponentSeeder::class);

    $served = Str::of(config('queue.worker_queues.generation'))->explode(',')->map(fn ($q) => trim($q));

    expect(Component::pluck('queue_name')->unique()->values()->all())
        ->each->toBeIn($served->all());
});

it('schedules a worker for the generation queues and another for everything else', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

    $default = $commands->first(fn (string $command) => str_contains($command, 'queue:work')
        && str_contains($command, '--queue='.config('queue.worker_queues.default').' '));

    $generation = $commands->first(fn (string $command) => str_contains($command, 'queue:work')
        && str_contains($command, config('queue.worker_queues.generation')));

    // --stop-when-empty is what makes a scheduled worker pick up new code.
    expect($default)->not->toBeNull()
        ->and($default)->toContain('--stop-when-empty')
        ->and($generation)->not->toBeNull()
        ->and($generation)->toContain('--stop-when-empty');
});

it('gives every LLM job longer than the call it waits on', function (string $job): void {
    // A worker that outruns its own job SIGKILLs it — exit 137, model credit
    // spent, nothing written, and three retries to spend it again.
    $instance = match ($job) {
        GenerateProductJob::class, RunQaPromptJob::class => new $job(GenerationTask::factory()->create()),
        default => new $job(Component::factory()->create()),
    };

    expect($instance->timeout)->toBeGreaterThan((int) config('llm.timeout'));
})->with([GenerateProductJob::class, RunQaPromptJob::class, GenerateTopicJob::class]);

it('does not let the generation worker outlive its jobs', function (): void {
    $command = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->first(fn (string $command) => str_contains($command, config('queue.worker_queues.generation')));

    preg_match('/--timeout=(\d+)/', (string) $command, $matches);

    expect((int) ($matches[1] ?? 0))->toBeGreaterThan((int) config('llm.timeout'));
});
