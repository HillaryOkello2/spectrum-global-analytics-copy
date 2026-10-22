<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\GenerationTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    /**
     * Columns added after their table's own migration had already run, which
     * are therefore the ones a database can be missing while `migrate` reports
     * nothing to do.
     *
     * Kept as a constant so a test can assert it against a freshly migrated
     * schema — a drift check that names a column which does not exist would
     * cry wolf during exactly the incident it exists for.
     *
     * @var array<string, array<int, string>>
     */
    public const REQUIRED_COLUMNS = [
        'generation_tasks' => ['qa_result', 'qa_verdict', 'qa_round', 'last_error', 'attempts'],
        'qa_reviews' => ['round', 'verdict', 'report', 'failed_sections', 'rerun_instructions'],
        'llm_call_logs' => ['context', 'success', 'latency_ms', 'is_probe'],
        'components' => ['qa_prompt_template', 'queue_name', 'is_virtual'],
        'products' => ['abstract', 'redacted_body', 'redaction_approved'],
        'subscriptions' => ['pending_tier_id', 'last_reminder_days'],
    ];

    public function handle(): int
    {
        $since = now()->subHours((int) $this->option('hours'));

        // Before anything else: if the code is running against a table that
        // does not have the columns it writes, nothing below will make sense
        // and half of it will throw.
        if ($this->schemaDrift()) {
            return self::FAILURE;
        }

        $this->components->info("Generation tasks queued since {$since->toDateTimeString()}");

        $this->statusBreakdown($since);
        $this->qaVerdicts($since);
        $this->stalledTasks();
        $this->queueDepth();
        $this->settings();

        return self::SUCCESS;
    }

    /**
     * Columns this application's code writes, checked against the columns the
     * database actually has.
     *
     * This is the failure that does not announce itself: the deploy succeeds,
     * `migrate` reports nothing to do, the models run and are paid, and every
     * job dies on an SQL error at the moment it tries to record the result. It
     * happens when a column is added by editing a migration that has already
     * run — the file's name is already in the `migrations` table, so the change
     * reaches fresh installs and nothing else.
     *
     * @return bool true when something is missing, and the caller should stop
     */
    private function schemaDrift(): bool
    {
        $required = self::REQUIRED_COLUMNS;

        $missing = [];

        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = [$table, '(whole table)', 'the migration that creates it has not run'];

                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = [$table, $column, 'code writes this; the database has no such column'];
                }
            }
        }

        if ($missing === []) {
            return false;
        }

        $this->components->error('Schema drift: the code is ahead of the database.');

        $this->table(['Table', 'Missing', 'Meaning'], $missing);

        $this->line('  Every job that writes one of these dies on an SQL error — after the model has');
        $this->line('  already been called and paid for. Tasks end up `failed`, never reaching the board.');
        $this->newLine();
        $this->line('  `php artisan migrate` reporting "Nothing to migrate" does NOT rule this out: a');
        $this->line('  column added by editing an already-run migration leaves that file\'s name in the');
        $this->line('  `migrations` table, so there is nothing for migrate to pick up.');
        $this->newLine();
        $this->line('  Fix: deploy the repair migration that adds these, run `php artisan migrate`, then');
        $this->line('  `php artisan generation:retry-failed --dry-run` to see what can be resumed.');

        return true;
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
