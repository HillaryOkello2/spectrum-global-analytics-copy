<?php

namespace App\Services\Llm\Drivers;

use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\LlmResult;
use Illuminate\Support\Facades\Http;

class GeminiClient implements LlmClient
{
    /**
     * @param  array{base_url: string, api_key: ?string, timeout?: int}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly string $modelId,
    ) {}

    public function generate(string $prompt): LlmResult
    {
        $response = Http::timeout($this->config['timeout'] ?? 120)
            ->retry(2, 1000)
            ->post(
                "{$this->config['base_url']}/v1beta/models/{$this->modelId}:generateContent?key={$this->config['api_key']}",
                [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                ],
            )
            ->throw();

        return new LlmResult(
            text: $response->json('candidates.0.content.parts.0.text', ''),
            model: $this->modelId,
        );
    }
}
