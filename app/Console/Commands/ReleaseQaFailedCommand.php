<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\GenerationTask;
use App\Models\User;
use App\Services\Generation\GenerationPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Put documents the QA gate is holding back on the proofreading board.
 *
 * `QA_ENFORCE_VERDICT=false` stops the gate holding anything *new*; it does
 * nothing for documents already parked in `qa_failed`, because that status was
 * written to their rows when they failed. This is how those come back.
 *
 * It goes through GenerationPipeline::releaseQaFailure() rather than running an
 * UPDATE, so every release is audited with who ordered it and why — the same
 * standard the per-document override is held to. A batch release is a bigger
 * act than a single override, not a smaller one.
 */
class ReleaseQaFailedCommand extends Command
{
    protected $signature = 'generation:release-qa-failed
        {--actor= : Email of the staff member ordering this; recorded on every audit entry}
        {--reason= : Why the hold is being lifted; recorded on every audit entry}
        {--component= : Limit to one component code, e.g. DB}
        {--hours= : Limit to tasks queued in the last N hours}
        {--dry-run : List what would be released, change nothing}';

    protected $description = 'Return QA-held documents to the proofreading board';

    public function handle(GenerationPipeline $pipeline): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $tasks = $this->tasks();

        if ($tasks->isEmpty()) {
            $this->components->info('Nothing is being held by the QA gate that matches.');

            return self::SUCCESS;
        }

        $this->table(
            ['Task', 'Component', 'Product', 'Verdict', 'Rounds', 'Queued'],
            $tasks->map(fn (GenerationTask $task) => [
                $task->id,
                $task->topic?->component?->code ?? '—',
                $task->product?->code ?? '—',
                $task->qa_verdict?->value ?? '—',
                $task->qa_round,
                $task->queued_at?->diffForHumans(),
            ])->all(),
        );

        if ($dryRun) {
            $this->components->info("{$tasks->count()} task(s) would be released. Nothing was changed.");

            return self::SUCCESS;
        }

        // Only validated once we know there is something to do, so a dry run
        // and an empty match need neither.
        $actor = $this->actor();
        $reason = $this->reason();

        if ($actor === null || $reason === null) {
            return self::FAILURE;
        }

        if ($this->input->isInteractive()
            && ! $this->confirm("Release {$tasks->count()} task(s) to the proofreading board?", true)) {
            $this->components->warn('Nothing was changed.');

            return self::SUCCESS;
        }

        $released = 0;

        foreach ($tasks as $task) {
            try {
                $pipeline->releaseQaFailure($task, $actor, $reason);
                $released++;
            } catch (\Throwable $e) {
                // One task that will not move must not strand the rest of the
                // batch — most likely it changed status since the list was read.
                $this->components->warn("Task {$task->id} could not be released: {$e->getMessage()}");
            }
        }

        $this->components->info("Released {$released} task(s) to the proofreading board, as {$actor->email}.");
        $this->line('  They appear under `awaiting_proofreading`, carrying the audit report that held them.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, GenerationTask>
     */
    private function tasks(): Collection
    {
        return GenerationTask::query()
            ->where('status', TaskStatus::QaFailed)
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

    /**
     * A real staff account, because the release is attributed to someone in the
     * audit log. Resolved before anything is written.
     */
    private function actor(): ?User
    {
        $email = $this->option('actor') ?: $this->ask('Email of the staff member ordering this release');

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
        $reason = $this->option('reason') ?: $this->ask('Why is the hold being lifted?');

        if (strlen((string) $reason) < 10) {
            // The same floor the per-document override enforces.
            $this->components->error('A reason of at least 10 characters is required; it is recorded on every entry.');

            return null;
        }

        return $reason;
    }
}
