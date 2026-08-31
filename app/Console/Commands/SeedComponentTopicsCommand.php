<?php

namespace App\Console\Commands;

use App\Models\Component;
use App\Services\Generation\GenerationPipeline;
use App\Services\Generation\TopicGenerator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Test-data utility: commission a topic for each Component so the catalogue has
 * something under every node.
 *
 * Topics come from the component's own topic_prompt via the model — the same
 * path the scheduler uses for the daily brief — so this exercises the client's
 * real prompt pack rather than stand-in text. With LLM_FAKE=true it costs
 * nothing.
 *
 *   php artisan demo:component-topics --generate
 *   php artisan demo:component-topics DB
 */
class SeedComponentTopicsCommand extends Command
{
    protected $signature = 'demo:component-topics
                            {component? : Component code (e.g. DB) or public id; omit for all}
                            {--generate : Queue a generation run for each topic straight away}';

    protected $description = 'Commission a demo topic per component, for testing';

    public function handle(TopicGenerator $topics, GenerationPipeline $pipeline): int
    {
        $components = $this->components();

        if ($components->isEmpty()) {
            $this->error("No component matches '{$this->argument('component')}'.");

            return self::FAILURE;
        }

        $queued = 0;
        $failed = 0;

        foreach ($components as $component) {
            if (! $component->canCommissionTopics()) {
                $this->warn("  {$component->code}  skipped — no prompt template seeded");
                $failed++;

                continue;
            }

            try {
                $topic = $topics->generate($component);
            } catch (Throwable $e) {
                $this->warn("  {$component->code}  failed — {$e->getMessage()}");
                $failed++;

                continue;
            }

            if ($this->option('generate')) {
                $pipeline->queueTopic($topic);
                $queued++;
            }

            $this->line("  {$component->code}  {$topic->frequency->value}  {$topic->title}");
        }

        $this->newLine();
        $this->info(($components->count() - $failed).' topic(s) ready.');

        if ($failed > 0) {
            $this->warn("{$failed} component(s) skipped or failed.");
        }

        if ($queued > 0) {
            $this->info("{$queued} generation job(s) queued — run: php artisan queue:work --stop-when-empty");
            $this->comment('Generated products have no abstract yet — one is written during proofreading. '
                .'Use `demo:proofread-products` to fill placeholders and approve them.');
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Component> */
    private function components(): Collection
    {
        $code = $this->argument('component');

        return Component::query()
            ->when($code, fn ($query) => $query->where(
                fn ($query) => $query->where('code', $code)->orWhere('public_id', $code),
            ))
            ->orderBy('sort_order')
            ->get();
    }
}
