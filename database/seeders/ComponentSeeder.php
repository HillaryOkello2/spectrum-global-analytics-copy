<?php

namespace Database\Seeders;

use App\Models\Component;
use App\Models\LlmProvider;
use Illuminate\Database\Seeder;

class ComponentSeeder extends Seeder
{
    /**
     * The nine Components, reproduced from Blueprint Annex 2 (FR-04). Components
     * are the top level of the catalogue: Pillars were removed in the 2026-08
     * scope change, and Annex 2's A10–A14 were dropped with them.
     *
     * The Component→LLM mapping is not specified in the requirements
     * (Assumptions §21, open question) — assigned round-robin here as a
     * placeholder until the client supplies the permanent mapping.
     */
    public const COMPONENTS = [
        ['code' => 'A1', 'name' => 'SGA Analytics Abstract Papers (AP)', 'batch' => 1],
        ['code' => 'A2', 'name' => 'SGA Analytics Essay Series (ES)', 'batch' => 1],
        ['code' => 'A3', 'name' => 'Analytics Book Series (BS)', 'batch' => 1],
        ['code' => 'A4', 'name' => 'Daily Strategic Intelligence Analytics Brief (DB)', 'batch' => 2],
        ['code' => 'A5', 'name' => 'Weekly Strategic Intelligence Analytics Highlights (WH)', 'batch' => 2],
        ['code' => 'A6', 'name' => 'Monthly Strategic Intelligence Analytics Focus (MF)', 'batch' => 2],
        ['code' => 'A7', 'name' => 'Strategic Analytics Research Papers (RP)', 'batch' => 3],
        ['code' => 'A8', 'name' => 'Analytics White Papers - Corporate (WP/C)', 'batch' => 3],
        ['code' => 'A9', 'name' => 'Analytics White Papers - Governmental (WP/G)', 'batch' => 3],
    ];

    public function run(): void
    {
        $providerIds = LlmProvider::orderBy('id')->pluck('id')->all();

        foreach (self::COMPONENTS as $index => $component) {
            Component::updateOrCreate(['code' => $component['code']], [
                ...$component,
                'assigned_llm_provider_id' => $providerIds[$index % count($providerIds)],
                'is_transactional' => $component['batch'] === 5,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
