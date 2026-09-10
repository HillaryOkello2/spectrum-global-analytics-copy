<?php

namespace App\Services\Llm\Drivers;

use App\Exceptions\EmptyLlmResponseException;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\LlmResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Chat-completions driver for OpenAI and OpenAI-compatible APIs
 * (DeepSeek, Moonshot/Kimi, MiniMax).
 */
class OpenAiCompatibleClient implements LlmClient
{
    /**
     * @param  array{base_url: string, api_key: ?string, timeout?: int, max_tokens?: int, thinking?: ?string}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly string $modelId,
    ) {}

    public function generate(string $prompt): LlmResult
    {
        $response = Http::withToken($this->config['api_key'])
            ->timeout($this->config['timeout'] ?? 120)
            ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException
                || in_array($e->response?->status(), [408, 429, 500, 502, 503, 504], true))
            ->post("{$this->config['base_url']}/chat/completions", [
                'model' => $this->modelId,
                'max_tokens' => $this->config['max_tokens'] ?? 4096,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                ...$this->thinkingOptions(),
            ])
            ->throw();

        $model = $response->json('model', $this->modelId);
        $text = (string) $response->json('choices.0.message.content', '');

        if (trim($text) === '') {
            throw EmptyLlmResponseException::for(
                $model,
                $response->json('choices.0.finish_reason'),
                $response->json('usage.completion_tokens_details.reasoning_tokens'),
            );
        }

        return new LlmResult(text: $text, model: $model);
    }

    /**
     * Sent only when this driver's config sets `thinking`. It is a DeepSeek
     * extension, not part of the OpenAI API — OpenAI rejects arguments it does
     * not know — so it must never reach a vendor that has not opted in.
     *
     * @return array<string, mixed>
     */
    private function thinkingOptions(): array
    {
        $mode = $this->config['thinking'] ?? null;

        if ($mode === null || $mode === '') {
            return [];
        }

        return ['thinking' => ['type' => $mode === 'enabled' ? 'enabled' : 'disabled']];
    }
}
