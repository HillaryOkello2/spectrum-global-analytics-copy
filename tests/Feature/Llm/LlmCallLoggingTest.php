<?php

use App\Exceptions\EmptyLlmResponseException;
use App\Models\LlmCallLog;
use App\Models\LlmProvider;
use App\Services\Llm\LlmErrorSanitizer;
use App\Services\Llm\LlmManager;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // The fake driver makes no HTTP call, and these tests are about what the
    // recorder sees on the wire.
    config()->set('llm.fake', false);
    config()->set('llm.drivers.anthropic.api_key', 'sk-ant-secret-key-value-123456789');
    config()->set('llm.drivers.anthropic.base_url', 'https://anthropic.test');
});

function anthropic(): LlmProvider
{
    return LlmProvider::factory()->create([
        'driver' => 'anthropic',
        'model_id' => 'claude-sonnet-5',
    ]);
}

function reply(string $text = 'The answer.'): array
{
    return ['model' => 'claude-sonnet-5', 'content' => [['type' => 'text', 'text' => $text]]];
}

it('records a successful call with its latency', function (): void {
    Http::fake(['*' => Http::response(reply())]);

    app(LlmManager::class)->for(anthropic(), LlmCallLog::CONTEXT_GENERATION)->generate('prompt');

    $log = LlmCallLog::sole();

    expect($log->success)->toBeTrue()
        ->and($log->error_code)->toBeNull()
        ->and($log->latency_ms)->toBeGreaterThanOrEqual(0)
        ->and($log->context)->toBe('Product generation')
        ->and($log->is_probe)->toBeFalse();
});

it('counts every attempt, retries included', function (): void {
    // One call that was rate-limited and succeeded on its retry is 2 requests
    // and 1 error, not 1 clean request — a provider that flaky must not read
    // as healthy, and nothing else in the system can see the difference.
    Http::fakeSequence()
        ->push(['error' => ['message' => 'Rate limited']], 429)
        ->push(reply());

    app(LlmManager::class)->for(anthropic())->generate('prompt');

    expect(LlmCallLog::count())->toBe(2)
        ->and(LlmCallLog::where('success', false)->count())->toBe(1)
        ->and(LlmCallLog::where('success', false)->first()->error_code)->toBe('429')
        ->and(LlmCallLog::where('success', true)->first()->error_code)->toBeNull();
});

it('records a call the provider rejected outright', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'model not found']], 404)]);

    expect(fn () => app(LlmManager::class)->for(anthropic())->generate('p'))
        ->toThrow(RequestException::class);

    $log = LlmCallLog::sole();

    expect($log->success)->toBeFalse()
        ->and($log->error_code)->toBe('404')
        ->and($log->error_message)->toBe('model not found');
});

it('records a connection failure as a network error, not a silence', function (): void {
    // Thrown the way Guzzle throws it, so the client marshals it into the
    // ConnectionFailed event the recorder listens for — a provider that is
    // simply unreachable has to leave a row, or it reads as idle.
    Http::fake(fn () => throw new ConnectException(
        'cURL error 7: Failed to connect to anthropic.test',
        new GuzzleRequest('POST', 'https://anthropic.test/v1/messages'),
    ));

    expect(fn () => app(LlmManager::class)->for(anthropic())->generate('p'))
        ->toThrow(ConnectionException::class);

    expect(LlmCallLog::count())->toBe(2)
        ->and(LlmCallLog::first()->error_code)->toBe('network')
        ->and(LlmCallLog::first()->success)->toBeFalse();
});

it('records a timeout as a timeout', function (): void {
    Http::fake(fn () => throw new ConnectException(
        'cURL error 28: Operation timed out after 600000 milliseconds',
        new GuzzleRequest('POST', 'https://anthropic.test/v1/messages'),
    ));

    expect(fn () => app(LlmManager::class)->for(anthropic())->generate('p'))
        ->toThrow(ConnectionException::class);

    expect(LlmCallLog::first()->error_code)->toBe('timeout');
});

it('corrects a 200 the driver then rejected', function (): void {
    // The whole token budget went on thinking: HTTP 200, no text. The attempt
    // was written as a success before the driver saw the body, so the recorder
    // has to go back and fix it rather than leave a phantom success.
    Http::fake(['*' => Http::response([
        'model' => 'claude-sonnet-5',
        'stop_reason' => 'max_tokens',
        'content' => [['type' => 'thinking', 'thinking' => '…']],
    ])]);

    expect(fn () => app(LlmManager::class)->for(anthropic())->generate('p'))
        ->toThrow(EmptyLlmResponseException::class);

    $log = LlmCallLog::sole();

    expect($log->success)->toBeFalse()
        ->and($log->error_code)->toBe('invalid_response')
        ->and($log->latency_ms)->toBeNull();
});

it('never stores the API key a vendor echoed back', function (): void {
    Http::fake(['*' => Http::response([
        'error' => ['message' => 'Incorrect API key provided: sk-ant-secret-key-value-123456789. Check your key.'],
    ], 401)]);

    expect(fn () => app(LlmManager::class)->for(anthropic())->generate('p'))
        ->toThrow(RequestException::class);

    $message = LlmCallLog::first()->error_message;

    expect($message)->not->toContain('sk-ant-secret-key-value-123456789')
        ->and($message)->toContain('[redacted]')
        ->and($message)->toContain('Incorrect API key provided');
});

it('masks credentials it was never told about and keeps messages to one line', function (): void {
    $sanitizer = app(LlmErrorSanitizer::class);

    expect($sanitizer->clean('Bad key AIzaSyA1b2C3d4E5f6G7h8I9j0K'))->not->toContain('AIzaSy')
        ->and($sanitizer->clean('Authorization: Bearer abcdef1234567890xyz'))->not->toContain('abcdef1234567890xyz')
        ->and($sanitizer->clean("line one\nline two"))->toBe('line one line two')
        ->and(strlen($sanitizer->clean(str_repeat('x', 900))))->toBeLessThanOrEqual(LlmErrorSanitizer::MAX_LENGTH);
});

it('marks probes as probes and gives them their own short timeout', function (): void {
    Http::fake(['*' => Http::response(reply('OK'))]);
    config()->set('llm.health.probe.max_tokens', 5);

    app(LlmManager::class)->probe(anthropic())->generate('Reply with: OK');

    $log = LlmCallLog::sole();

    expect($log->is_probe)->toBeTrue()
        ->and($log->context)->toBe('Health probe');

    Http::assertSent(fn ($request) => $request['max_tokens'] === 5);
});

it('does not record HTTP traffic that has nothing to do with an LLM', function (): void {
    Http::fake(['*' => Http::response(['ok' => true])]);

    Http::get('https://gateway.test/status');

    expect(LlmCallLog::count())->toBe(0);
});

it('still records a call when the fake driver is in use', function (): void {
    // Local and CI run on LLM_FAKE, which makes no HTTP call. Without the
    // fallback row the health dashboard would be permanently empty there.
    config()->set('llm.fake', true);

    app(LlmManager::class)->for(anthropic())->generate('prompt');

    expect(LlmCallLog::sole()->success)->toBeTrue();
});

it('prunes call history past the retention window', function (): void {
    $provider = anthropic();

    LlmCallLog::factory()->for($provider, 'provider')->at(now()->subDays(40))->create();
    LlmCallLog::factory()->for($provider, 'provider')->at(now()->subDays(3))->create();

    $this->artisan('model:prune', ['--model' => [LlmCallLog::class]])->assertSuccessful();

    expect(LlmCallLog::count())->toBe(1);
});
