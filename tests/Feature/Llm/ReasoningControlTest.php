<?php

use App\Exceptions\EmptyLlmResponseException;
use App\Jobs\GenerateProductJob;
use App\Jobs\GenerateTopicJob;
use App\Jobs\Middleware\FailOnTokenCeiling;
use App\Jobs\RunQaPromptJob;
use App\Models\GenerationTask;
use App\Services\Llm\Drivers\GeminiClient;
use App\Services\Llm\Drivers\OpenAiCompatibleClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function compatibleConfig(array $overrides = []): array
{
    return [
        'base_url' => 'https://example.test/v1',
        'api_key' => 'k',
        'max_tokens' => 8000,
        'timeout' => 600,
        ...$overrides,
    ];
}

function completion(string $content = 'The answer.', string $finish = 'stop', ?int $reasoningTokens = null): array
{
    return [
        'model' => 'deepseek-flash',
        'choices' => [['finish_reason' => $finish, 'message' => ['content' => $content]]],
        'usage' => ['completion_tokens_details' => ['reasoning_tokens' => $reasoningTokens]],
    ];
}

/**
 * Stands in for a queued job: records whether the middleware marked it failed,
 * which is what stops the worker from retrying it.
 */
function recordingJob(): object
{
    return new class
    {
        public ?Throwable $failedWith = null;

        public function fail($exception = null): void
        {
            $this->failedWith = $exception;
        }
    };
}

it('switches reasoning off by default for the vendors that reason', function (string $driver, string $model): void {
    // Both reason by default and pay for it out of max_tokens. DeepSeek V4 Pro
    // spent all 8,000 on a CC brief; Kimi K2.6 spent 15,999 of 16,000 on an ES
    // essay. Neither wrote a word.
    Http::fake(['*' => Http::response(completion())]);

    (new OpenAiCompatibleClient(config("llm.drivers.{$driver}"), $model))->generate('p');

    Http::assertSent(fn (Request $request): bool => $request['thinking'] === ['type' => 'disabled']);
})->with([
    'DeepSeek' => ['deepseek', 'deepseek-flash'],
    'Moonshot' => ['moonshot', 'kimi-k2.6'],
]);

it('never sends the thinking parameter to a vendor that has not opted in', function (): void {
    // A DeepSeek extension. OpenAI rejects request arguments it does not know.
    Http::fake(['*' => Http::response(completion())]);

    (new OpenAiCompatibleClient(config('llm.drivers.openai'), 'gpt-4o'))->generate('p');

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('thinking', $request->data()));
});

it('can switch DeepSeek reasoning back on', function (): void {
    Http::fake(['*' => Http::response(completion())]);

    (new OpenAiCompatibleClient(compatibleConfig(['thinking' => 'enabled']), 'deepseek-flash'))->generate('p');

    Http::assertSent(fn (Request $request): bool => $request['thinking'] === ['type' => 'enabled']);
});

it('says when reasoning is what used up the budget', function (): void {
    // The exact failure from 2026-09-10: 8,000 reasoning tokens, no text.
    Http::fake(['*' => Http::response(completion('', 'length', 8000))]);

    expect(fn () => (new OpenAiCompatibleClient(compatibleConfig(), 'deepseek-v4-pro'))->generate('p'))
        ->toThrow(EmptyLlmResponseException::class, 'spent 8000 tokens reasoning');
});

it('fails a job outright when the model ran out of tokens before writing', function (): void {
    $job = recordingJob();
    $ceiling = EmptyLlmResponseException::for('deepseek-v4-pro', 'length', 8000);

    expect(fn () => (new FailOnTokenCeiling)->handle($job, fn () => throw $ceiling))
        ->toThrow(EmptyLlmResponseException::class);

    // Marked failed, so the worker does not pay for the same tokens on retry.
    expect($job->failedWith)->toBe($ceiling);
});

it('still lets other failures retry', function (Throwable $failure): void {
    $job = recordingJob();

    expect(fn () => (new FailOnTokenCeiling)->handle($job, fn () => throw $failure))
        ->toThrow($failure::class);

    expect($job->failedWith)->toBeNull();
})->with([
    'a connection timeout' => [new ConnectionException('timed out')],
    'an empty reply for another reason' => [EmptyLlmResponseException::for('m', 'content_filter')],
]);

it('guards every job that calls a model', function (string $jobClass): void {
    $task = GenerationTask::factory()->create();

    $job = $jobClass === GenerateTopicJob::class
        ? new GenerateTopicJob($task->topic->component)
        : new $jobClass($task);

    expect(collect($job->middleware())->contains(fn (object $middleware): bool => $middleware instanceof FailOnTokenCeiling))
        ->toBeTrue();
})->with([
    'generation' => GenerateProductJob::class,
    'QA' => RunQaPromptJob::class,
    'topic commissioning' => GenerateTopicJob::class,
]);

function geminiReply(string $text = 'The answer.'): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']]];
}

it('caps Gemini thinking by default', function (): void {
    // gemini-3.7-flash has no off switch — a positive thinkingBudget is the one
    // control it honours — so thinking is capped rather than disabled.
    Http::fake(['*' => Http::response(geminiReply())]);

    (new GeminiClient(config('llm.drivers.gemini'), 'gemini-3.7-flash'))->generate('p');

    Http::assertSent(fn (Request $request): bool => $request['generationConfig']['thinkingConfig'] === ['thinkingBudget' => 2048]
        && $request['generationConfig']['maxOutputTokens'] === config('llm.drivers.gemini.max_tokens'));
});

it('leaves Gemini thinking at the model default when no budget is set', function (): void {
    Http::fake(['*' => Http::response(geminiReply())]);

    (new GeminiClient(compatibleConfig(['thinking_budget' => '']), 'gemini-3.7-flash'))->generate('p');

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('thinkingConfig', $request['generationConfig']));
});

it('says when Gemini thinking used up the budget', function (): void {
    // What a 40-token ceiling produced live: 36 tokens of thinking, no text.
    Http::fake(['*' => Http::response([
        'candidates' => [['finishReason' => 'MAX_TOKENS']],
        'usageMetadata' => ['thoughtsTokenCount' => 36],
    ])]);

    expect(fn () => (new GeminiClient(compatibleConfig(), 'gemini-3.7-flash'))->generate('p'))
        ->toThrow(EmptyLlmResponseException::class, 'spent 36 tokens reasoning');
});
