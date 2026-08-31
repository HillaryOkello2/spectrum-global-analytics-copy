<?php

namespace Database\Seeders;

use App\Models\Component;
use App\Services\Generation\TopicGenerator;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * One demo topic per Component, so the catalogue has content to browse during
 * frontend development.
 *
 * Topics are commissioned from each component's own topic_prompt, the same way
 * the scheduler commissions the daily brief — so what gets seeded exercises the
 * client's real prompt pack. Keep LLM_FAKE=true unless you mean to spend credits.
 *
 * Not part of DatabaseSeeder — run explicitly:
 *   php artisan db:seed --class=DemoTopicSeeder
 *
 * To seed topics AND queue their generation runs in one step, use
 * `php artisan demo:component-topics --generate` instead.
 */
class DemoTopicSeeder extends Seeder
{
    public function run(): void
    {
        $topics = app(TopicGenerator::class);

        Component::query()->orderBy('sort_order')->each(function (Component $component) use ($topics): void {
            if (! $component->canCommissionTopics()) {
                return;
            }

            try {
                $topics->generate($component);
            } catch (Throwable $e) {
                $this->command?->warn("  {$component->code} skipped — {$e->getMessage()}");
            }
        });
    }
}
