<?php

namespace App\Services\Llm;

use App\Models\LlmProvider;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\Drivers\AnthropicClient;
use App\Services\Llm\Drivers\FakeLlmClient;
use App\Services\Llm\Drivers\GeminiClient;
use App\Services\Llm\Drivers\OpenAiCompatibleClient;
use InvalidArgumentException;

/**
 * Resolves the LlmClient for an LlmProvider row. OpenAI, DeepSeek, Moonshot, and
 * MiniMax all expose OpenAI-compatible chat-completion APIs and share one driver
 * with different base URLs/keys (config/llm.php).
 */
class LlmManager
{
    public function for(LlmProvider $provider): LlmClient
    {
        if (config('llm.fake')) {
            return new FakeLlmClient($provider->model_id);
        }

        $config = config("llm.drivers.{$provider->driver}");

        if ($config === null) {
            throw new InvalidArgumentException("No configuration for LLM driver [{$provider->driver}].");
        }

        return match ($config['client']) {
            'fake' => new FakeLlmClient($provider->model_id),
            'anthropic' => new AnthropicClient($config, $provider->model_id),
            'gemini' => new GeminiClient($config, $provider->model_id),
            'openai-compatible' => new OpenAiCompatibleClient($config, $provider->model_id),
            default => throw new InvalidArgumentException("Unsupported LLM client [{$config['client']}]."),
        };
    }
}
