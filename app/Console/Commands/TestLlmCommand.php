<?php

namespace App\Console\Commands;

use App\Models\Component;
use App\Models\GenerationTask;
use App\Models\LlmProvider;
use App\Services\Generation\GenerationPipeline;
use App\Services\Generation\PromptRenderer;
use App\Services\Generation\TopicGenerator;
use App\Services\Llm\LlmManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Checks that the configured API keys actually answer, without running the
 * queue or writing a product. Three depths, because the failures are different:
 * a wrong key fails in a second, a wrong model id fails on the first call, and
 * a token budget too small for the client's prompts only shows up on a real
 * document.
 *
 *   php artisan llm:test                 every provider, one-line reply  (~5s)
 *   php artisan llm:test anthropic       one provider
 *   php artisan llm:test --topic         also commission a live topic   (~10s)
 *   php artisan llm:test --document      full document + QA report      (~3m)
 *   php artisan llm:test --persist       the real pipeline, saved to the DB (~3m)
 *
 * --document writes both files to storage/app/llm-test so you can read the
 * document the client's prompt actually produces, and what the SGA-QCP-v2
 * vetting protocol says about it, without going through the Task Board. It
 * keeps nothing: the commissioned topic is deleted again on the way out.
 *
 * --persist is the opposite — it runs the genuine queue path (GenerateProductJob
 * then RunQaPromptJob, transitions and all) inline instead of on a worker, and
 * leaves the topic, generation_task and product rows behind for inspection. It
 * is the same code the scheduler runs at 00:05; only the worker is skipped.
 */
class TestLlmCommand extends Command
{
    protected $signature = 'llm:test
        {driver? : Limit to one driver (anthropic, gemini, openai, deepseek, moonshot, minimax)}
        {--topic : Also commission a real topic for a component on that provider}
        {--document : Also generate a full document and run the QA prompt over it — slow, and it costs tokens}
        {--out= : Directory to write the document and QA report to (default storage/app/llm-test)}
        {--persist : Run the real pipeline and KEEP the topic, task and product rows in the database}';

    protected $description = 'Send a live prompt to each configured LLM provider and report what came back';

    public function handle(LlmManager $llm, TopicGenerator $topics, PromptRenderer $renderer, GenerationPipeline $pipeline): int
    {
        if (config('llm.fake')) {
            $this->warn('LLM_FAKE is on — every provider resolves to the fake driver. Set LLM_FAKE=false to test for real.');

            return self::FAILURE;
        }

        $providers = LlmProvider::query()
            ->when($this->argument('driver'), fn ($q, $driver) => $q->where('driver', $driver))
            ->orderBy('id')
            ->get();

        if ($providers->isEmpty()) {
            $this->error('No matching providers. Run: php artisan db:seed --class=LlmProviderSeeder');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($providers as $provider) {
            $failed += $this->check($provider, $llm) ? 0 : 1;
        }

        if ($this->option('persist')) {
            $this->newLine();
            $this->persistRun($providers, $topics, $pipeline);
        } elseif ($this->option('topic') || $this->option('document')) {
            $this->newLine();
            $this->deepCheck($providers, $llm, $topics, $renderer);
        }

        $this->newLine();
        $this->line($failed === 0
            ? '<info>All '.$providers->count().' provider(s) responded.</info>'
            : "<comment>{$failed} of {$providers->count()} provider(s) failed.</comment>");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(LlmProvider $provider, LlmManager $llm): bool
    {
        $label = str_pad($provider->name, 18).str_pad($provider->model_id, 22);

        if (blank(config("llm.drivers.{$provider->driver}.api_key"))) {
            $this->line("{$label} <comment>skipped — ".strtoupper($provider->driver).'_API_KEY is not set</comment>');

            return true;
        }

        $started = microtime(true);

        try {
            $reply = $llm->for($provider)->generate('Reply with exactly: OK');
        } catch (Throwable $e) {
            $this->line("{$label} <fg=red>FAILED</> ".$this->oneLine($e->getMessage()));

            return false;
        }

        $this->line(sprintf('%s<info>ok</info> %.1fs — %s', $label, microtime(true) - $started, $this->oneLine($reply->text, 60)));

        return true;
    }

    /**
     * The genuine pipeline, start to finish, written to the database.
     *
     * @param  Collection<int, LlmProvider>  $providers
     */
    private function persistRun(Collection $providers, TopicGenerator $topics, GenerationPipeline $pipeline): void
    {
        $component = $this->componentFor($providers);

        if ($component === null) {
            return;
        }

        $this->info("Live run on {$component->code} via {$component->assignedLlmProvider->model_id} — rows WILL be kept");

        $started = microtime(true);

        try {
            $topic = $topics->generate($component);
        } catch (Throwable $e) {
            $this->line('  <fg=red>topic FAILED</> '.$this->oneLine($e->getMessage()));

            return;
        }

        $this->line(sprintf('  <info>topic #%d</info> %.1fs — %s', $topic->id, microtime(true) - $started, $this->oneLine($topic->title, 70)));

        // queueTopic dispatches GenerateProductJob, which chains RunQaPromptJob.
        // Forcing the sync connection runs that real chain inline, so no worker
        // is needed and the command cannot exit before the rows are written.
        config(['queue.default' => 'sync']);

        $started = microtime(true);

        try {
            $task = $pipeline->queueTopic($topic);
        } catch (Throwable $e) {
            $this->line('  <fg=red>pipeline FAILED</> '.$this->oneLine($e->getMessage()));
            $this->failureRows($topic->id);

            return;
        }

        $this->line(sprintf('  <info>pipeline</info> %.1fs', microtime(true) - $started));
        $this->newLine();

        $this->rows($task->refresh()->load(['product', 'topic']));
    }

    private function rows(GenerationTask $task): void
    {
        $product = $task->product;

        $this->line('<comment>Rows written:</comment>');
        $this->table(
            ['table', 'id', 'key', 'status'],
            [
                ['topics', $task->topic_id, $this->oneLine($task->topic->title, 46), $task->topic->source],
                ['generation_tasks', $task->id, $task->public_id, $task->status->value],
                ['products', $product?->id ?? '-', $product?->code ?? '-', $product?->status->value ?? '-'],
            ],
        );

        if ($product === null) {
            return;
        }

        $this->line('  products.body      '.strlen((string) $product->body).' chars, '.str_word_count((string) $product->body).' words');
        $this->line('  products.abstract  '.($product->abstract === null ? '<comment>null - written by the proofreader, never the LLM</comment>' : strlen($product->abstract).' chars'));
        $this->line('  tasks.qa_result    '.strlen((string) $task->qa_result).' chars');
        $this->newLine();

        $this->line('<comment>See it in the database:</comment>');
        $this->line("  mysql> SELECT id, code, title, status, CHAR_LENGTH(body) FROM products WHERE id = {$product->id};");
        $this->newLine();
        $this->line("On the Task Board as <info>{$task->status->value}</info> - GET /api/v1/admin/tasks/{$task->public_id}");
    }

    /**
     * A failed run still leaves rows behind - say which, so they can be found.
     */
    private function failureRows(int $topicId): void
    {
        $task = GenerationTask::where('topic_id', $topicId)->latest('id')->first();

        if ($task === null) {
            return;
        }

        $this->line("  task #{$task->id} is <fg=red>{$task->status->value}</> - ".$this->oneLine((string) $task->last_error));
    }

    /**
     * @param  Collection<int, LlmProvider>  $providers
     */
    private function componentFor(Collection $providers): ?Component
    {
        // Only providers that actually have a key - otherwise this lands on
        // whichever component sorts first and fails for the boring reason the
        // summary above already reported.
        $usable = $providers->filter(fn (LlmProvider $p) => filled(config("llm.drivers.{$p->driver}.api_key")));

        $component = Component::query()
            ->whereIn('assigned_llm_provider_id', $usable->pluck('id'))
            ->whereNotNull('topic_prompt')
            ->orderBy('code')
            ->first();

        if ($component === null) {
            $this->warn('No component with a topic prompt is assigned to a provider that has an API key - skipping.');
        }

        return $component;
    }

    /**
     * @param  Collection<int, LlmProvider>  $providers
     */
    private function deepCheck(Collection $providers, LlmManager $llm, TopicGenerator $topics, PromptRenderer $renderer): void
    {
        $component = $this->componentFor($providers);

        if ($component === null) {
            return;
        }

        $this->info("Deep check on {$component->code} via {$component->assignedLlmProvider->model_id}");

        $started = microtime(true);

        try {
            $topic = $topics->generate($component);
        } catch (Throwable $e) {
            $this->line('  <fg=red>topic FAILED</> '.$this->oneLine($e->getMessage()));

            return;
        }

        $this->line(sprintf('  <info>topic</info> %.1fs — %s', microtime(true) - $started, $this->oneLine($topic->title, 70)));

        foreach ($topic->variables ?? [] as $key => $value) {
            $this->line("    [{$key}] ".$this->oneLine((string) $value, 90));
        }

        if (! $this->option('document')) {
            $this->comment('  (add --document to generate the full document from this topic)');
            $topic->delete();

            return;
        }

        $client = $llm->for($component->assignedLlmProvider);

        // A throwaway document ref: nothing is persisted, this only proves the
        // prompt renders and the model returns a usable body.
        $reference = "SGA.{$component->ref_code}.000.00.00";
        $started = microtime(true);

        try {
            $body = $client->generate($renderer->renderProductPrompt($topic, $reference))->text;
        } catch (Throwable $e) {
            $this->line('  <fg=red>document FAILED</> '.$this->oneLine($e->getMessage()));
            $topic->delete();

            return;
        }

        preg_match_all('/\[[A-Z][A-Z0-9_]*\]/', $body, $matches);

        $this->line(sprintf('  <info>document</info> %.1fs — %s words, %s chars', microtime(true) - $started, str_word_count($body), strlen($body)));
        $this->line('  unresolved placeholders: '.($matches[0] ? implode(', ', array_unique($matches[0])) : 'none'));

        // Exactly what RunQaPromptJob sends: the vetting protocol, then the
        // document it is vetting, on the same provider that wrote it.
        $started = microtime(true);

        try {
            $qa = $client->generate($renderer->renderQaPrompt($topic)."\n\n---\n\n".$body)->text;
        } catch (Throwable $e) {
            $this->line('  <fg=red>QA FAILED</> '.$this->oneLine($e->getMessage()));
            $qa = null;
        }

        if ($qa !== null) {
            $this->line(sprintf('  <info>QA</info> %.1fs — %s words', microtime(true) - $started, str_word_count($qa)));
        }

        $topic->delete();

        $this->writeOut($component->code, $body, $qa);
    }

    /**
     * The document is thousands of words — echoing it into the terminal is
     * useless, so both halves go to disk and only the head is printed.
     */
    private function writeOut(string $componentCode, string $body, ?string $qa): void
    {
        $directory = rtrim($this->option('out') ?: storage_path('app/llm-test'), '/');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $stamp = now()->format('Ymd-His');
        $written = [];

        foreach (['document' => $body, 'qa' => $qa] as $kind => $contents) {
            if ($contents === null) {
                continue;
            }

            $path = "{$directory}/{$componentCode}-{$stamp}-{$kind}.md";
            file_put_contents($path, $contents);
            $written[] = $path;
        }

        $this->newLine();

        foreach ($written as $path) {
            $this->line("  written: <info>{$path}</info>");
        }

        $this->newLine();
        $this->line('<comment>--- document, first 800 chars ---</comment>');
        $this->line(str($body)->limit(800)->value());

        if ($qa !== null) {
            $this->newLine();
            $this->line('<comment>--- QA report, first 800 chars ---</comment>');
            $this->line(str($qa)->limit(800)->value());
        }
    }

    private function oneLine(string $text, int $limit = 120): string
    {
        return str(preg_replace('/\s+/', ' ', trim($text)))->limit($limit)->value();
    }
}
