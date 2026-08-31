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
            ['name' => 'Gemini 3.7 Flash', 'vendor' => 'Google', 'driver' => 'gemini', 'model_id' => 'gemini-3.7-flash'],
            ['name' => 'GPT-4o', 'vendor' => 'OpenAI', 'driver' => 'openai', 'model_id' => 'gpt-4o'],
            ['name' => 'DeepSeek-V4-Pro', 'vendor' => 'DeepSeek', 'driver' => 'deepseek', 'model_id' => 'deepseek-v4-pro'],
            ['name' => 'Kimi K2.6', 'vendor' => 'Moonshot AI', 'driver' => 'moonshot', 'model_id' => 'kimi-k2.6'],
            ['name' => 'MiniMax M3', 'vendor' => 'MiniMax', 'driver' => 'minimax', 'model_id' => 'minimax-m3'],
        ];

        foreach ($providers as $provider) {
            LlmProvider::updateOrCreate(['driver' => $provider['driver']], $provider);
        }
    }
}
