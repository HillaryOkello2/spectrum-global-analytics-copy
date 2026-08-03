<?php

namespace App\Console\Commands;

use App\Enums\Frequency;
use App\Models\Component;
use App\Models\Topic;
use App\Services\Generation\GenerationPipeline;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Test-data utility: give each Component a topic, so the catalogue has something
 * under every node. Idempotent — re-running updates the existing topics rather
 * than duplicating them.
 *
 *   php artisan demo:component-topics --generate
 *   php artisan demo:component-topics A4
 */
class SeedComponentTopicsCommand extends Command
{
    protected $signature = 'demo:component-topics
                            {component? : Component code (e.g. A4) or public id; omit for all}
                            {--generate : Queue a generation run for each topic straight away}';

    protected $description = 'Create a demo topic per component, for testing';

    /** Cadence per component code; anything unlisted is quarterly. */
    private const CADENCE = [
        'A1' => Frequency::Monthly,
        'A2' => Frequency::Monthly,
        'A3' => Frequency::Monthly,
        'A4' => Frequency::Daily,
        'A5' => Frequency::Weekly,
        'A6' => Frequency::Monthly,
    ];

    public function handle(GenerationPipeline $pipeline): int
    {
        $components = $this->components();

        if ($components->isEmpty()) {
            $this->error("No component matches '{$this->argument('component')}'.");

            return self::FAILURE;
        }

        $queued = 0;

        foreach ($components as $component) {
            $frequency = self::CADENCE[$component->code] ?? Frequency::Quarterly;

            $topic = Topic::updateOrCreate(
                ['component_id' => $component->id, 'title' => $component->name],
                [
                    'frequency' => $frequency,
                    'prompt_text' => $this->prompt($component, $frequency),
                    'qa_prompt_text' => 'Review the draft below for structure, analytic tone, hedging of claims '
                        .'and internal consistency. Report a score out of 100 and list the checks a human '
                        .'proofreader should prioritise.',
                    'is_active' => true,
                ],
            );

            if ($this->option('generate')) {
                $pipeline->queueTopic($topic);
                $queued++;
            }

            $this->line("  {$component->code}  {$frequency->value}  {$topic->title}");
        }

        $this->newLine();
        $this->info("{$components->count()} topic(s) ready.");

        if ($queued > 0) {
            $this->info("{$queued} generation job(s) queued — run: php artisan queue:work --queue=llm --stop-when-empty");
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

    private function prompt(Component $component, Frequency $frequency): string
    {
        return "{$component->name}\n\n"
            ."Produce a {$frequency->value} strategic intelligence product in the format of the "
            ."{$component->name} series. "
            .'Open with an executive summary, then key judgements, drivers and constraints, indicators to '
            .'watch, and an outlook. State confidence explicitly and avoid advocacy language.';
    }
}
