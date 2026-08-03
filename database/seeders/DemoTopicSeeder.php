<?php

namespace Database\Seeders;

use App\Enums\Frequency;
use App\Models\Component;
use App\Models\Topic;
use Illuminate\Database\Seeder;

/**
 * One demo topic per Component, so the catalogue has content to browse during
 * frontend development. Idempotent: re-running updates the prompts rather than
 * duplicating topics.
 *
 * Not part of DatabaseSeeder — run explicitly:
 *   php artisan db:seed --class=DemoTopicSeeder
 *
 * To seed topics AND queue their generation runs in one step, use
 * `php artisan demo:component-topics --generate` instead.
 */
class DemoTopicSeeder extends Seeder
{
    /** @var array<int, array{component: string, title: string, frequency: Frequency}> */
    private const TOPICS = [
        ['component' => 'A1', 'title' => 'Middle Power Alignment in a Multipolar Order', 'frequency' => Frequency::Monthly],
        ['component' => 'A2', 'title' => 'Semiconductor Export Controls and Technology Sovereignty', 'frequency' => Frequency::Monthly],
        ['component' => 'A3', 'title' => 'Energy Transition Cycles and Infrastructure Lock-In', 'frequency' => Frequency::Monthly],
        ['component' => 'A4', 'title' => 'Daily Diplomatic Signals and Capital Flows Brief', 'frequency' => Frequency::Daily],
        ['component' => 'A5', 'title' => 'Weekly Defence Procurement Highlights', 'frequency' => Frequency::Weekly],
        ['component' => 'A6', 'title' => 'Monthly Maritime Logistics and Chokepoint Focus', 'frequency' => Frequency::Monthly],
        ['component' => 'A7', 'title' => 'Deterrence Posture and Force Modernisation', 'frequency' => Frequency::Quarterly],
        ['component' => 'A8', 'title' => 'Corporate Exposure to State-Aligned Cyber Operations', 'frequency' => Frequency::Quarterly],
        ['component' => 'A9', 'title' => 'Sanctions Compliance for Government Contracting', 'frequency' => Frequency::Quarterly],
    ];

    public function run(): void
    {
        foreach (self::TOPICS as $topic) {
            $component = Component::where('code', $topic['component'])->firstOrFail();

            Topic::updateOrCreate(
                ['component_id' => $component->id, 'title' => $topic['title']],
                [
                    'frequency' => $topic['frequency'],
                    'prompt_text' => $this->prompt($topic['title'], $topic['frequency']),
                    'qa_prompt_text' => $this->qaPrompt(),
                    'is_active' => true,
                ],
            );
        }
    }

    private function prompt(string $title, Frequency $frequency): string
    {
        return "{$title}\n\n"
            ."Produce a {$frequency->value} strategic intelligence product on the subject above. "
            .'Open with an executive summary, then key judgements, drivers and constraints, indicators to watch, and an outlook. '
            .'State confidence explicitly and avoid advocacy language.';
    }

    private function qaPrompt(): string
    {
        return 'Review the draft below for structure, analytic tone, hedging of claims and internal consistency. '
            .'Report a score out of 100 and list the checks a human proofreader should prioritise.';
    }
}
