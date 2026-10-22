<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\GenerationTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Answers "the models ran, so where are the products?" from the data.
 *
 * Read-only — it writes nothing and changes no state, so it is safe to run
 * against production.
 *
 * Generated documents that never reach the proofreading board are almost
 * always sitting in one of four places, and this names which: held by the QA
 * gate (`qa_failed`), mid-rewrite (`revising`), stalled because the job never
 * ran (`qa_running`, `generating`, `queued`), or errored (`failed`). The
 * board's default view shows `awaiting_proofreading`, so anything in the other
 * states is invisible there while being perfectly present in the database.
 */
class DiagnoseGenerationCommand extends Command
{
    protected $signature = 'generation:diagnose
        {--hours=48 : How far back to look}
        {--stalled=30 : Minutes with no progress before a task counts as stalled}';

    protected $description = 'Explain where generated documents are, and why they are not on the proofreading board';

    public function handle(): int
    {
        $since = now()->subHours((int) $this->option('hours'));

        $this->components->info("Generation tasks queued since {$since->toDateTimeString()}");

        $this->statusBreakdown($since);
        $this->qaVerdicts($since);
        $this->stalledTasks();
        $this->queueDepth();
        $this->settings();

        return self::SUCCESS;
    }

    /**
     * Where the tasks actually are. The board shows `awaiting_proofreading`;
     * every other row here is a document that exists but is not on it.
     */
    private function statusBreakdown(\DateTimeInterface $since): void
    {
        $counts = GenerationTask::query()
            ->where('queued_at', '>=', $since)
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        if ($counts->isEmpty()) {
            $this->newLine();
            $this->warn('  No generation tasks at all in this window.');
            $this->line('  The models did not run, or they ran against an older deployment.');
            $this->line('  Check: php artisan schedule:list, and the topics table for last_generated_at.');

            return;
        }

        $this->table(
            ['Task status', 'Count', 'On the proofreading board?'],
            $counts->map(fn (int $total, string $status) => [
                $status,
                $total,
                $this->boardNote($status),
            ])->values()->all(),
        );
    }

    private function boardNote(string $status): string
    {
        return match ($status) {
            TaskStatus::AwaitingProofreading->value => 'YES — waiting to be picked up',
            TaskStatus::InProofreading->value => 'yes — already claimed',
            TaskStatus::QaFailed->value => 'NO — held by the QA gate, needs tasks/{task}/qa-override',
            TaskStatus::Revising->value => 'NO — being rewritten after a RED LIGHT audit',
            TaskStatus::QaRunning->value => 'NO — audit in flight (or the job never ran)',
            TaskStatus::Generating->value, TaskStatus::Queued->value => 'NO — not written yet',
            TaskStatus::Failed->value => 'NO — the job errored; see last_error',
            default => 'past proofreading',
        };
    }

    /**
     * A RED LIGHT wave is the difference between "the gate is working" and
     * "the gate is misfiring". UNKNOWN in bulk means the auditor answered
     * off-template and the parser could read no verdict at all — which is a
     * fault in the audit, not in the documents.
     */
    private function qaVerdicts(\DateTimeInterface $since): void
    {
        $verdicts = GenerationTask::query()
            ->where('queued_at', '>=', $since)
            ->whereNotNull('qa_verdict')
            ->groupBy('qa_verdict')
            ->selectRaw('qa_verdict, count(*) as total')
            ->pluck('total', 'qa_verdict');

        if ($verdicts->isEmpty()) {
            $this->line('  No QA verdicts recorded — nothing has been audited yet.');
            $this->newLine();

            return;
        }

        $this->table(
            ['QA verdict', 'Count'],
            $verdicts->map(fn (int $total, string $verdict) => [$verdict, $total])->values()->all(),
        );

        if (($verdicts['unknown'] ?? 0) > 0) {
            $this->warn('  UNKNOWN verdicts present: the auditor answered off-template and no verdict could be read.');
            $this->line('  With QA_UNKNOWN_VERDICT=fail these are failed outright. Default is `pass`.');
        }
    }

    /**
     * A task in a transient state with nothing happening to it is a job that
     * never ran — a worker that is down, a queue nothing listens to, or a job
     * the worker killed for running past its timeout.
     */
    private function stalledTasks(): void
    {
        $cutoff = now()->subMinutes((int) $this->option('stalled'));

        $stalled = GenerationTask::query()
            ->whereIn('status', [
                TaskStatus::Queued,
                TaskStatus::Generating,
                TaskStatus::QaRunning,
                TaskStatus::Revising,
            ])
            ->where('updated_at', '<', $cutoff)
            ->with('topic.component')
            ->latest('queued_at')
            ->limit(20)
            ->get();

        if ($stalled->isEmpty()) {
            $this->line('  No stalled tasks — nothing is sitting in a transient state.');
            $this->newLine();

            return;
        }

        $this->warn("  {$stalled->count()} task(s) stuck with no progress for over {$this->option('stalled')} minutes:");

        $this->table(
            ['Task', 'Component', 'Queue', 'Status', 'Round', 'Last change', 'Error'],
            $stalled->map(fn (GenerationTask $task) => [
                $task->id,
                $task->topic?->component?->code ?? '—',
                $task->topic?->component?->queue_name ?? '—',
                $task->status->value,
                $task->qa_round,
                $task->updated_at?->diffForHumans(),
                str($task->last_error ?? '')->limit(40),
            ])->all(),
        );

        $this->line('  A whole queue stuck here means its worker is not running, or the queue');
        $this->line('  is missing from QUEUE_WORKER_LLM_QUEUES / the Horizon supervisor.');
        $this->newLine();
    }

    /**
     * What is still waiting to be worked, and what died trying.
     */
    private function queueDepth(): void
    {
        if (config('queue.default') !== 'database') {
            $this->line('  Queue connection is '.config('queue.default').' — check depth in Horizon instead.');
            $this->newLine();

            return;
        }

        $pending = DB::table('jobs')->groupBy('queue')->selectRaw('queue, count(*) as total')->pluck('total', 'queue');
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHours((int) $this->option('hours')))->count();

        if ($pending->isNotEmpty()) {
            $this->table(
                ['Queue', 'Jobs waiting'],
                $pending->map(fn (int $total, string $queue) => [$queue, $total])->values()->all(),
            );
            $this->line('  Jobs waiting is normal mid-run. Jobs waiting while nothing progresses is a dead worker.');
        } else {
            $this->line('  No jobs waiting.');
        }

        if ($failed > 0) {
            $this->warn("  {$failed} failed job(s) in this window — inspect with: php artisan queue:failed");
        }

        $this->newLine();
    }

    private function settings(): void
    {
        $this->components->info('Settings that decide whether a document reaches the board');

        $this->table(['Setting', 'Value', 'Effect'], [
            ['qa.enforce', var_export(config('qa.enforce'), true),
                config('qa.enforce')
                    ? 'ON — a RED LIGHT document is rewritten, then held'
                    : 'OFF — every document goes to the board whatever the audit says'],
            ['qa.max_revisions', config('qa.max_revisions'), 'rewrites before a document is given up on'],
            ['qa.unknown_verdict', config('qa.unknown_verdict'), 'what an unreadable audit counts as'],
            ['publishing.redaction', var_export(config('publishing.redaction'), true), 'whether proofreading hands over to a redaction pass'],
        ]);

        $held = GenerationTask::where('status', TaskStatus::QaFailed)->count();

        if ($held > 0) {
            $this->newLine();
            $this->warn("  {$held} task(s) are held in qa_failed in total.");
            $this->line('  They are recoverable: POST /admin/tasks/{task}/qa-override takes one on with a');
            $this->line('  named proofreader and a reason. Setting QA_ENFORCE_VERDICT=false stops new ones');
            $this->line('  being held, but does not release these.');
        }
    }
}
