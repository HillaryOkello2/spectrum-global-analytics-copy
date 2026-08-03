<?php

namespace App\Services\Llm\Drivers;

use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\LlmResult;
use Illuminate\Support\Facades\Http;

class AnthropicClient implements LlmClient
{
    /**
     * @param  array{base_url: string, api_key: ?string, timeout?: int, max_tokens?: int}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly string $modelId,
    ) {}

    public function generate(string $prompt): LlmResult
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->config['api_key'],
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout($this->config['timeout'] ?? 120)
            ->retry(2, 1000)
            ->post("{$this->config['base_url']}/v1/messages", [
                'model' => $this->modelId,
                'max_tokens' => $this->config['max_tokens'] ?? 4096,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ])
            ->throw();

        return new LlmResult(
            text: $response->json('content.0.text', ''),
            model: $response->json('model', $this->modelId),
        );
    }
}
