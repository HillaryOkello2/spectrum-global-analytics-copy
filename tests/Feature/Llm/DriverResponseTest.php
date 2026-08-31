<?php

use App\Exceptions\EmptyLlmResponseException;
use App\Services\Llm\Drivers\AnthropicClient;
use App\Services\Llm\Drivers\GeminiClient;
use App\Services\Llm\Drivers\OpenAiCompatibleClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

$config = ['base_url' => 'https://example.test', 'api_key' => 'k', 'max_tokens' => 32000, 'timeout' => 600];

it('reads past a leading thinking block', function () use ($config): void {
    // A reasoning model puts its thinking first; the answer is the second block,
    // so content[0].text is an empty string.
    Http::fake(['*' => Http::response([
        'model' => 'claude-sonnet-5',
        'stop_reason' => 'end_turn',
        'content' => [
            ['type' => 'thinking', 'thinking' => 'weighing the options'],
            ['type' => 'text', 'text' => 'The answer.'],
        ],
    ])]);

    $result = (new AnthropicClient($config, 'claude-sonnet-5'))->generate('prompt');

    expect($result->text)->toBe('The answer.')
        ->and($result->model)->toBe('claude-sonnet-5');
});

it('joins multiple text blocks', function () use ($config): void {
    Http::fake(['*' => Http::response([
        'content' => [
            ['type' => 'text', 'text' => 'First. '],
            ['type' => 'text', 'text' => 'Second.'],
        ],
    ])]);

    expect((new AnthropicClient($config, 'claude-sonnet-5'))->generate('p')->text)
        ->toBe('First. Second.');
});

it('refuses to return an empty body when the token ceiling is hit', function () use ($config): void {
    // The whole budget went on thinking: 200 OK, no text block. Storing that
    // silently is what put an empty product through the pipeline.
    Http::fake(['*' => Http::response([
        'model' => 'claude-sonnet-5',
        'stop_reason' => 'max_tokens',
        'content' => [['type' => 'thinking', 'thinking' => '...']],
    ])]);

    expect(fn () => (new AnthropicClient($config, 'claude-sonnet-5'))->generate('p'))
        ->toThrow(EmptyLlmResponseException::class, 'max_tokens');
});

it('does not retry a request the provider rejected outright', function () use ($config): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'model not found']], 404)]);

    expect(fn () => (new AnthropicClient($config, 'claude-sonnet-5'))->generate('p'))
        ->toThrow(RequestException::class);

    Http::assertSentCount(1);
});

it('retries a transient provider failure', function () use ($config): void {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => 'busy'], 503)
        ->push(['content' => [['type' => 'text', 'text' => 'Recovered.']]], 200),
    ]);

    expect((new AnthropicClient($config, 'claude-sonnet-5'))->generate('p')->text)
        ->toBe('Recovered.');

    Http::assertSentCount(2);
});

it('skips gemini thought parts and sends the token ceiling', function () use ($config): void {
    Http::fake(['*' => Http::response([
        'candidates' => [[
            'finishReason' => 'STOP',
            'content' => ['parts' => [
                ['text' => 'internal', 'thought' => true],
                ['text' => 'The answer.'],
            ]],
        ]],
    ])]);

    expect((new GeminiClient($config, 'gemini-3.7-flash'))->generate('p')->text)
        ->toBe('The answer.');

    Http::assertSent(fn ($request) => $request['generationConfig']['maxOutputTokens'] === 32000);
});

it('rejects an empty openai-compatible completion', function () use ($config): void {
    Http::fake(['*' => Http::response([
        'model' => 'gpt-4o',
        'choices' => [['finish_reason' => 'length', 'message' => ['content' => '']]],
    ])]);

    expect(fn () => (new OpenAiCompatibleClient($config, 'gpt-4o'))->generate('p'))
        ->toThrow(EmptyLlmResponseException::class);
});
