<?php

namespace App\Services\Llm\Drivers;

use App\Exceptions\EmptyLlmResponseException;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\LlmResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GeminiClient implements LlmClient
{
    /**
     * @param  array{base_url: string, api_key: ?string, timeout?: int, max_tokens?: int, thinking_level?: ?string, thinking_budget?: int|string|null}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly string $modelId,
    ) {}

    public function generate(string $prompt): LlmResult
    {
        $response = Http::timeout($this->config['timeout'] ?? 120)
            ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException
                || in_array($e->response?->status(), [408, 429, 500, 502, 503, 504], true))
            ->post(
                "{$this->config['base_url']}/v1beta/models/{$this->modelId}:generateContent?key={$this->config['api_key']}",
                [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => $this->config['max_tokens'] ?? 4096,
                        ...$this->thinkingOptions(),
                    ],
                ],
            )
            ->throw();

        $text = $this->textFrom($response->json('candidates.0.content.parts', []));

        if (trim($text) === '') {
            throw EmptyLlmResponseException::for(
                $this->modelId,
                $response->json('candidates.0.finishReason'),
                $response->json('usageMetadata.thoughtsTokenCount'),
            );
        }

        return new LlmResult(text: $text, model: $this->modelId);
    }

    /**
     * Gemini's thinking comes out of maxOutputTokens: given a 40-token ceiling,
     * gemini-3.7-flash spent 36 thinking and returned no text at all.
     *
     * The control that works differs by model, so both are configurable and
     * the level wins when both are set:
     * - thinkingLevel "minimal" switches thinking fully off on 3.5/3.6-flash;
     *   3.7-flash rejects it with a 400.
     * - thinkingBudget 0 switches it off on 2.5-flash, is ignored by 3.7-flash
     *   (105 thinking tokens on a one-line sum) and rejected by 3.6-flash. A
     *   positive budget is honoured as a cap on 3.7-flash (32 produced 34).
     * Neither set leaves the model's own default.
     *
     * @return array<string, mixed>
     */
    private function thinkingOptions(): array
    {
        $level = $this->config['thinking_level'] ?? null;

        if ($level !== null && $level !== '') {
            return ['thinkingConfig' => ['thinkingLevel' => $level]];
        }

        $budget = $this->config['thinking_budget'] ?? null;

        if ($budget === null || $budget === '') {
            return [];
        }

        return ['thinkingConfig' => ['thinkingBudget' => (int) $budget]];
    }

    /**
     * Same reason as AnthropicClient::textFrom() — the reply is a list of parts
     * and a reasoning model can put a `thought` part ahead of the answer, so
     * parts[0] is not reliably the text.
     *
     * @param  array<int, array{text?: string, thought?: bool}>  $parts
     */
    private function textFrom(array $parts): string
    {
        $text = '';

        foreach ($parts as $part) {
            if (($part['thought'] ?? false) === true) {
                continue;
            }

            $text .= $part['text'] ?? '';
        }

        return $text;
    }
}
