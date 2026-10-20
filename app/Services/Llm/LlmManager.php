<?php

namespace App\Services\Llm;

use App\Models\LlmCallLog;
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
 *
 * Every client handed out is wrapped in LoggingLlmClient, so the LLM health
 * dashboard sees a call whoever made it — instrumentation nobody has to
 * remember to add at the call site.
 */
class LlmManager
{
    public function __construct(private readonly LlmCallRecorder $recorder) {}

    public function for(LlmProvider $provider, string $context = LlmCallLog::CONTEXT_GENERATION): LlmClient
    {
        return new LoggingLlmClient(
            $this->driverFor($provider),
            $this->recorder,
            $provider,
            $context,
        );
    }

    /**
     * The scheduled health check (§4.2): the shortest prompt that proves the
     * credentials and the endpoint work, with a token ceiling and a timeout of
     * its own so a probe can never cost or block what a generation run does.
     */
    public function probe(LlmProvider $provider): LlmClient
    {
        return new LoggingLlmClient(
            $this->driverFor($provider, [
                'max_tokens' => (int) config('llm.health.probe.max_tokens', 5),
                'timeout' => (int) config('llm.health.probe.timeout', 30),
            ]),
            $this->recorder,
            $provider,
            LlmCallLog::CONTEXT_PROBE,
            isProbe: true,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function driverFor(LlmProvider $provider, array $overrides = []): LlmClient
    {
        if (config('llm.fake')) {
            return new FakeLlmClient($provider->model_id);
        }

        $config = config("llm.drivers.{$provider->driver}");

        if ($config === null) {
            throw new InvalidArgumentException("No configuration for LLM driver [{$provider->driver}].");
        }

        $config = [...$config, ...$overrides];

        return match ($config['client']) {
            'fake' => new FakeLlmClient($provider->model_id),
            'anthropic' => new AnthropicClient($config, $provider->model_id),
            'gemini' => new GeminiClient($config, $provider->model_id),
            'openai-compatible' => new OpenAiCompatibleClient($config, $provider->model_id),
            default => throw new InvalidArgumentException("Unsupported LLM client [{$config['client']}]."),
        };
    }
}
