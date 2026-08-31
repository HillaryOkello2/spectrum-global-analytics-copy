<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\GenerationTask;
use App\Models\User;
use App\Services\Generation\GenerationPipeline;
use Illuminate\Console\Command;

/**
 * Test-data utility: walk generated products through both proofreading stages
 * with obvious placeholder text, so the FIFO release queue has something in it
 * without a human typing nine abstracts.
 *
 * The abstract is deliberately not LLM-generated, so the fake driver cannot
 * supply one — this stands in for the proofreader during development only.
 *
 *   php artisan demo:proofread-products
 */
class ProofreadDemoProductsCommand extends Command
{
    protected $signature = 'demo:proofread-products {--limit=50 : Maximum tasks to process}';

    protected $description = 'Fill placeholder abstracts and redactions, then approve, for testing';

    public function handle(GenerationPipeline $pipeline): int
    {
        $actor = User::role(User::SYSTEM_ADMIN)->first();

        if ($actor === null) {
            $this->error('No System Admin found — run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        $tasks = GenerationTask::query()
            ->whereIn('status', [TaskStatus::AwaitingProofreading, TaskStatus::InProofreading, TaskStatus::AwaitingRedaction])
            ->with('product.component')
            ->orderBy('queued_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('Nothing awaiting proofreading.');

            return self::SUCCESS;
        }

        foreach ($tasks as $task) {
            if ($task->status === TaskStatus::AwaitingProofreading) {
                $pipeline->openProofreading($task, $actor);
                $task->refresh();
            }

            if ($task->status === TaskStatus::InProofreading) {
                $pipeline->submitProofread($task, $actor, [
                    // Title and byline pass through unchanged: a real proofreader
                    // may correct them, the demo has nothing to correct them to.
                    'title' => $task->product->title,
                    'byline' => $task->product->byline,
                    'abstract' => $this->abstract($task),
                    'body' => $task->product->body,
                ]);
                $task->refresh();
            }

            if ($task->status === TaskStatus::AwaitingRedaction) {
                $pipeline->submitRedaction($task, $actor, $this->redaction($task));
                $task->refresh();
            }

            $this->line("  {$task->product->code}  {$task->status->value}  {$task->product->title}");
        }

        $this->newLine();
        $this->info("{$tasks->count()} product(s) approved and queued for release.");
        $this->comment('They are not live yet — run: php artisan products:release');

        return self::SUCCESS;
    }

    private function abstract(GenerationTask $task): string
    {
        return "Placeholder abstract for \"{$task->product->title}\" ({$task->product->component->name}). "
            .'This stands in for the proofreader-written abstract during local development and is not real analysis. '
            .'It is what visitors and non-entitled subscribers see in place of the full document.';
    }

    private function redaction(GenerationTask $task): string
    {
        return "Placeholder redacted document for \"{$task->product->title}\".\n\n"
            .'Sensitive judgements, sourcing and figures would be withheld here. Produced by a local development '
            .'stub — not real analysis.';
    }
}
