<?php

namespace Database\Seeders;

use App\Models\LlmProvider;
use Illuminate\Database\Seeder;

class LlmProviderSeeder extends Seeder
{
    /**
     * The six LLM providers fixed by the blueprint (§13.1, FR-19).
     *
     * Keyed on driver, not name: model ids and display names get revised as
     * vendors retire versions, and re-keying on name would leave the stale row
     * behind as a duplicate.
     */
    public function run(): void
    {
        $providers = [
            ['name' => 'Claude Sonnet 5', 'vendor' => 'Anthropic', 'driver' => 'anthropic', 'model_id' => 'claude-sonnet-5'],
            // 3.6 rather than 3.7: on this key 3.7-flash answered every full
            // document with 503 "high demand" (2026-09-10) while 3.6 wrote one
            // in 45s — and only 3.6 lets thinking be switched fully off.
            ['name' => 'Gemini 3.6 Flash', 'vendor' => 'Google', 'driver' => 'gemini', 'model_id' => 'gemini-3.6-flash'],
            ['name' => 'GPT-4o', 'vendor' => 'OpenAI', 'driver' => 'openai', 'model_id' => 'gpt-4o'],
            // V4 Pro is discontinued on 2026-09-14: calls are re-routed to V4.1
            // Flash and billed at its price. Pinned to Flash rather than moved.
            ['name' => 'DeepSeek V4.1 Flash', 'vendor' => 'DeepSeek', 'driver' => 'deepseek', 'model_id' => 'deepseek-flash'],
            ['name' => 'Kimi K2.6', 'vendor' => 'Moonshot AI', 'driver' => 'moonshot', 'model_id' => 'kimi-k2.6'],
            ['name' => 'MiniMax M3', 'vendor' => 'MiniMax', 'driver' => 'minimax', 'model_id' => 'minimax-m3'],
        ];

        foreach ($providers as $provider) {
            LlmProvider::updateOrCreate(['driver' => $provider['driver']], $provider);
        }
    }
}
