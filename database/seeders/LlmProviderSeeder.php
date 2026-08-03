<?php

namespace Database\Seeders;

use App\Models\LlmProvider;
use Illuminate\Database\Seeder;

class LlmProviderSeeder extends Seeder
{
    /**
     * The six LLM providers fixed by the blueprint (§13.1, FR-19).
     */
    public function run(): void
    {
        $providers = [
            ['name' => 'Claude 3.5 Sonnet', 'vendor' => 'Anthropic', 'driver' => 'anthropic', 'model_id' => 'claude-3-5-sonnet'],
            ['name' => 'Gemini 1.5 Pro', 'vendor' => 'Google', 'driver' => 'gemini', 'model_id' => 'gemini-1.5-pro'],
            ['name' => 'GPT-4o', 'vendor' => 'OpenAI', 'driver' => 'openai', 'model_id' => 'gpt-4o'],
            ['name' => 'DeepSeek-V4-Pro', 'vendor' => 'DeepSeek', 'driver' => 'deepseek', 'model_id' => 'deepseek-v4-pro'],
            ['name' => 'Kimi K2.6', 'vendor' => 'Moonshot AI', 'driver' => 'moonshot', 'model_id' => 'kimi-k2.6'],
            ['name' => 'MiniMax M3', 'vendor' => 'MiniMax', 'driver' => 'minimax', 'model_id' => 'minimax-m3'],
        ];

        foreach ($providers as $provider) {
            LlmProvider::updateOrCreate(['name' => $provider['name']], $provider);
        }
    }
}
