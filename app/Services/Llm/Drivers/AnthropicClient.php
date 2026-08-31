<?php

namespace App\Services\Llm\Drivers;

use App\Exceptions\EmptyLlmResponseException;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\LlmResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AnthropicClient implements LlmClient
{
    /**
     * @param  array{base_url: string, api_key: ?string, timeout?: int, max_tokens?: int, thinking?: ?string, effort?: string}  $config
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
            ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException
                || in_array($e->response?->status(), [408, 429, 500, 502, 503, 504], true))
            ->post("{$this->config['base_url']}/v1/messages", [
                'model' => $this->modelId,
                'max_tokens' => $this->config['max_tokens'] ?? 4096,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                ...$this->thinkingOptions(),
            ])
            ->throw();

        $model = $response->json('model', $this->modelId);
        $text = $this->textFrom($response->json('content', []));

        if (trim($text) === '') {
            throw EmptyLlmResponseException::for($model, $response->json('stop_reason'));
        }

        return new LlmResult(text: $text, model: $model);
    }

    /**
     * Older models reject the `thinking` key outright, so an empty setting
     * leaves it off the payload entirely and takes the provider default.
     *
     * @return array<string, mixed>
     */
    private function thinkingOptions(): array
    {
        $mode = $this->config['thinking'] ?? null;

        if ($mode === null || $mode === '') {
            return [];
        }

        if ($mode !== 'adaptive') {
            return ['thinking' => ['type' => 'disabled']];
        }

        return [
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => $this->config['effort'] ?? 'medium'],
        ];
    }

    /**
     * The reply is a list of content blocks, and only the `text` ones are the
     * answer. Reasoning models put a `thinking` block first, so reading
     * content[0] returns an empty string on those.
     *
     * @param  array<int, array{type?: string, text?: string}>  $content
     */
    private function textFrom(array $content): string
    {
        $text = '';

        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        return $text;
    }
}
