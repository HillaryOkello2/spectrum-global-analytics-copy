<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Jobs\GenerateProductJob;
use App\Jobs\RunQaPromptJob;
use App\Models\GenerationTask;
use App\Models\User;
use App\Services\Generation\GenerationPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Resume generation tasks that died on an error, at the stage they reached.
 *
 * Built for the case where the jobs failed for a reason outside the documents
 * themselves — a missing column, a revoked key, a provider outage — so the work
 * is sound and only the run was lost.
 *
 * A task whose document was already written is resumed at the audit, not at
 * generation. That matters: these are long documents, and re-generating one
 * that already exists pays the model twice and throws away the draft a
 * proofreader could have had.
 */
class RetryFailedTasksCommand extends Command
{
    protected $signature = 'generation:retry-failed
        {--actor= : Email of the staff member ordering this; recorded on every audit entry}
        {--reason= : Why these are being retried; recorded on every audit entry}
        {--component= : Limit to one component code, e.g. DB}
        {--hours= : Limit to tasks queued in the last N hours}
        {--dry-run : List what would be retried, and from which stage, changing nothing}';

    protected $description = 'Requeue failed generation tasks, resuming at the audit where the document already exists';

    public function handle(GenerationPipeline $pipeline): int
    {
        $tasks = $this->tasks();

        if ($tasks->isEmpty()) {
            $this->components->info('No failed tasks match.');

            return self::SUCCESS;
        }

        $this->table(
            ['Task', 'Component', 'Product', 'Resumes at', 'Failed with'],
            $tasks->map(fn (GenerationTask $task) => [
                $task->id,
                $task->topic?->component?->code ?? '—',
                $task->product?->code ?? '—',
                $this->resumable($task) ? 'audit (document kept)' : 'generation (no document)',
                str($task->last_error ?? '')->limit(44),
            ])->all(),
        );

        $resumable = $tasks->filter(fn (GenerationTask $task) => $this->resumable($task))->count();
        $this->line("  {$resumable} of {$tasks->count()} already have a written document and skip straight to the audit.");
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->components->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        $actor = $this->actor();
        $reason = $this->reason();

        if ($actor === null || $reason === null) {
            return self::FAILURE;
        }

        if ($this->input->isInteractive()
            && ! $this->confirm("Requeue {$tasks->count()} task(s)?", true)) {
            $this->components->warn('Nothing was changed.');

            return self::SUCCESS;
        }

        $counts = ['audit' => 0, 'generation' => 0];

        foreach ($tasks as $task) {
            try {
                $target = $pipeline->retryFailed($task, $actor, $reason);

                // Dispatched after the pipeline has committed the row, so a
                // worker cannot pick the task up while it still reads `failed`.
                if ($target === TaskStatus::QaRunning) {
                    RunQaPromptJob::dispatch($task->refresh());
                    $counts['audit']++;
                } else {
                    GenerateProductJob::dispatch($task->refresh());
                    $counts['generation']++;
                }
            } catch (Throwable $e) {
                $this->components->warn("Task {$task->id} could not be requeued: {$e->getMessage()}");
            }
        }

        $this->components->info(
            "Requeued {$counts['audit']} at the audit and {$counts['generation']} at generation, as {$actor->email}."
        );
        $this->line('  They run on each component\'s own queue. Watch with: php artisan generation:diagnose');

        return self::SUCCESS;
    }

    /**
     * A written body is the evidence that generation finished and only the
     * audit was lost.
     */
    private function resumable(GenerationTask $task): bool
    {
        return $task->product !== null && filled($task->product->body);
    }

    /**
     * @return Collection<int, GenerationTask>
     */
    private function tasks(): Collection
    {
        return GenerationTask::query()
            ->where('status', TaskStatus::Failed)
            ->when($this->option('component'), fn ($query, string $code) => $query->whereHas(
                'topic.component',
                fn ($component) => $component->where('code', $code),
            ))
            ->when($this->option('hours'), fn ($query, string $hours) => $query->where(
                'queued_at', '>=', now()->subHours((int) $hours),
            ))
            ->with(['topic.component', 'product'])
            ->orderBy('queued_at')
            ->get();
    }

    private function actor(): ?User
    {
        $email = $this->option('actor') ?: $this->ask('Email of the staff member ordering this retry');

        $actor = User::where('email', $email)->first();

        if ($actor === null) {
            $this->components->error("No user with the email [{$email}].");

            return null;
        }

        if (! $actor->can('proofread products')) {
            $this->components->error("{$actor->email} does not hold `proofread products`.");

            return null;
        }

        return $actor;
    }

    private function reason(): ?string
    {
        $reason = $this->option('reason') ?: $this->ask('Why are these being retried?');

        if (strlen((string) $reason) < 10) {
            $this->components->error('A reason of at least 10 characters is required; it is recorded on every entry.');

            return null;
        }

        return $reason;
    }
}
