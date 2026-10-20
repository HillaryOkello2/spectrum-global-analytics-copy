<?php

namespace App\Services\Llm;

use App\Exceptions\EmptyLlmResponseException;
use App\Models\LlmCallLog;
use App\Models\LlmProvider;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one llm_call_logs row per HTTP attempt made inside a `record()` call.
 *
 * Attempts are counted from the HTTP client's own events rather than around
 * `LlmClient::generate()`, because the drivers retry internally
 * (`Http::retry(2, …)`). Wrapping generate() alone would record one row for
 * what was three calls to the vendor, and the dashboard's whole purpose is to
 * show how flaky a vendor is — a provider that only answers on its second try
 * must not read as healthy.
 *
 * Only calls made while a scope is open are recorded, so the payment gateway
 * and every other HTTP caller are untouched.
 */
class LlmCallRecorder
{
    /** @var array{provider_id: int, context: string, is_probe: bool}|null */
    private ?array $scope = null;

    private ?float $attemptStartedAt = null;

    /** The row written for the most recent attempt in the open scope. */
    private ?LlmCallLog $lastAttempt = null;

    private int $attemptsInScope = 0;

    public function __construct(private readonly LlmErrorSanitizer $sanitizer) {}

    /**
     * Run $callback with recording switched on for $provider.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function record(LlmProvider $provider, string $context, bool $isProbe, Closure $callback)
    {
        $previous = [$this->scope, $this->attemptStartedAt, $this->lastAttempt, $this->attemptsInScope];

        $this->scope = ['provider_id' => $provider->id, 'context' => $context, 'is_probe' => $isProbe];
        $this->attemptStartedAt = null;
        $this->lastAttempt = null;
        $this->attemptsInScope = 0;

        $openedAt = microtime(true);

        try {
            $result = $callback();

            $this->settleSuccess($openedAt);

            return $result;
        } catch (Throwable $e) {
            $this->settleFailure($e, $openedAt);

            throw $e;
        } finally {
            [$this->scope, $this->attemptStartedAt, $this->lastAttempt, $this->attemptsInScope] = $previous;
        }
    }

    public function isRecording(): bool
    {
        return $this->scope !== null;
    }

    public function attemptStarted(): void
    {
        if ($this->scope === null) {
            return;
        }

        $this->attemptStartedAt = microtime(true);
    }

    public function attemptFinished(Response $response): void
    {
        if ($this->scope === null) {
            return;
        }

        $this->write(
            success: $response->successful(),
            errorCode: $response->successful() ? null : $this->sanitizer->codeForStatus($response->status()),
            errorMessage: $response->successful() ? null : $this->sanitizer->fromResponse($response),
        );
    }

    public function attemptFailed(Throwable $exception): void
    {
        if ($this->scope === null) {
            return;
        }

        $this->write(
            success: false,
            errorCode: $this->codeFor($exception),
            errorMessage: $this->sanitizer->fromThrowable($exception),
        );
    }

    /**
     * A 200 that the driver then rejected — an empty body, or a reply the
     * parser could not use — is a failed call, not a successful one. The row
     * was already written as a success by attemptFinished(), so it is corrected
     * here rather than duplicated.
     */
    private function settleFailure(Throwable $e, float $openedAt): void
    {
        $code = $this->codeFor($e);
        $message = $this->sanitizer->fromThrowable($e);

        if ($this->lastAttempt === null) {
            // No HTTP call happened at all: the fake driver, or a failure
            // before the request went out. Still a call the admin asked for,
            // and a provider with nothing but these is not "idle".
            $this->write(
                success: false,
                errorCode: $code,
                errorMessage: $message,
                startedAt: $openedAt,
            );

            return;
        }

        if ($this->lastAttempt->success) {
            $this->lastAttempt->forceFill([
                'success' => false,
                'latency_ms' => null,
                'error_code' => $code,
                'error_message' => $message,
            ])->save();
        }
    }

    /**
     * The fake driver makes no HTTP call, so without this every local and CI
     * run would leave the health dashboard permanently empty.
     */
    private function settleSuccess(float $openedAt): void
    {
        if ($this->attemptsInScope === 0) {
            $this->write(success: true, errorCode: null, errorMessage: null, startedAt: $openedAt);
        }
    }

    private function write(bool $success, ?string $errorCode, ?string $errorMessage, ?float $startedAt = null): void
    {
        $startedAt ??= $this->attemptStartedAt ?? microtime(true);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        try {
            $this->lastAttempt = LlmCallLog::create([
                'llm_provider_id' => $this->scope['provider_id'],
                'started_at' => now()->subMilliseconds($latencyMs),
                'finished_at' => now(),
                'success' => $success,
                'latency_ms' => $success ? $latencyMs : null,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'context' => $this->scope['context'],
                'is_probe' => $this->scope['is_probe'],
            ]);

            $this->attemptsInScope++;
        } catch (Throwable $e) {
            // Instrumentation must never take down a generation run that the
            // model has already been paid for.
            Log::warning('Failed to record an LLM call.', ['error' => $e->getMessage()]);
        } finally {
            $this->attemptStartedAt = null;
        }
    }

    /**
     * §2.2 of the frontend contract: an HTTP status as a string, or one of a
     * short set of slugs.
     */
    private function codeFor(Throwable $e): string
    {
        return match (true) {
            $e instanceof EmptyLlmResponseException => 'invalid_response',
            $e instanceof RequestException => (string) ($e->response?->status() ?? 'unknown'),
            $e instanceof ConnectionException => str_contains(strtolower($e->getMessage()), 'tim')
                ? 'timeout'
                : 'network',
            default => 'unknown',
        };
    }
}
