<?php

namespace App\Services\Llm;

use App\Models\LlmCallLog;
use App\Models\LlmProvider;
use App\Services\Llm\DTOs\LlmCredits;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-provider credit signal for the LLM health dashboard.
 *
 * Vendors that publish a balance endpoint (DeepSeek, Moonshot, MiniMax) are
 * queried directly. The others (Anthropic, Gemini, OpenAI) have no such API on
 * a normal key — for those we infer availability from the latest probe / call
 * errors, which is exactly how an empty account shows up (402, "insufficient
 * balance", quota / billing refusals).
 */
class LlmCreditsService
{
    public const SOURCE_BALANCE_API = 'balance_api';

    public const SOURCE_INFERRED = 'inferred';

    /**
     * Drivers that expose a balance (or account-query) HTTP endpoint.
     *
     * @var array<int, string>
     */
    private const BALANCE_DRIVERS = ['deepseek', 'moonshot', 'minimax'];

    /**
     * Phrases vendors use when the account has no spendable credit left.
     *
     * @var array<int, string>
     */
    private const EXHAUSTED_PHRASES = [
        'insufficient balance',
        'insufficient credit',
        'insufficient funds',
        'exceeded_current_quota',
        'exceeded current quota',
        'credit exhausted',
        'out of credit',
        'out of credits',
        'no remaining credits',
        'billing hard limit',
        'payment required',
        'account balance is not enough',
        'balance is not enough',
    ];

    public function for(LlmProvider $provider): LlmCredits
    {
        if (in_array($provider->driver, self::BALANCE_DRIVERS, true)) {
            // Balance HTTP calls are skipped under LLM_FAKE — generation itself
            // is stubbed — but inference still reads the call log so a recorded
            // 402 probe still shows as exhausted in tests and local dashboards.
            if (config('llm.fake')) {
                return new LlmCredits(
                    source: self::SOURCE_BALANCE_API,
                    available: true,
                    balance: '100.00',
                    currency: 'USD',
                    checkedAt: CarbonImmutable::now()->toIso8601ZuluString(),
                    detail: 'Stub balance (LLM_FAKE=true).',
                );
            }

            return $this->fromBalanceApi($provider);
        }

        return $this->inferred($provider);
    }

    private function fromBalanceApi(LlmProvider $provider): LlmCredits
    {
        $config = config("llm.drivers.{$provider->driver}");
        $apiKey = $config['api_key'] ?? null;
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');

        if (! filled($apiKey) || $baseUrl === '') {
            return new LlmCredits(
                source: self::SOURCE_BALANCE_API,
                available: null,
                detail: 'API key or base URL is not configured.',
            );
        }

        try {
            return match ($provider->driver) {
                'deepseek' => $this->deepseek($baseUrl, $apiKey),
                'moonshot' => $this->moonshot($baseUrl, $apiKey),
                'minimax' => $this->minimax($baseUrl, $apiKey),
                default => new LlmCredits(
                    source: self::SOURCE_BALANCE_API,
                    available: null,
                    detail: 'No balance fetcher for this driver.',
                ),
            };
        } catch (Throwable $e) {
            Log::warning('LLM balance fetch failed', [
                'driver' => $provider->driver,
                'message' => $e->getMessage(),
            ]);

            // Fall back to inference so a flaky balance endpoint does not hide
            // a real out-of-credit signal already in the call log.
            $inferred = $this->inferred($provider);

            return new LlmCredits(
                source: self::SOURCE_BALANCE_API,
                available: $inferred->available,
                checkedAt: CarbonImmutable::now()->toIso8601ZuluString(),
                detail: 'Balance endpoint failed: '.$e->getMessage().'. Using call-log inference.',
            );
        }
    }

    private function deepseek(string $baseUrl, string $apiKey): LlmCredits
    {
        // Chat API lives under /v1; balance is on the origin: /user/balance.
        $url = $this->origin($baseUrl).'/user/balance';

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(10)
            ->retry(1, 250, fn ($e) => $e instanceof ConnectionException)
            ->get($url)
            ->throw();

        $infos = $response->json('balance_infos', []);
        $row = $this->preferredBalanceRow(is_array($infos) ? $infos : []);
        $total = isset($row['total_balance']) ? (string) $row['total_balance'] : null;
        $currency = isset($row['currency']) ? (string) $row['currency'] : null;
        $isAvailable = (bool) $response->json('is_available', false);

        if ($total !== null && (float) $total <= 0) {
            $isAvailable = false;
        }

        return new LlmCredits(
            source: self::SOURCE_BALANCE_API,
            available: $isAvailable,
            balance: $total,
            currency: $currency,
            checkedAt: CarbonImmutable::now()->toIso8601ZuluString(),
            detail: $isAvailable ? null : 'Account reports no spendable balance.',
        );
    }

    private function moonshot(string $baseUrl, string $apiKey): LlmCredits
    {
        $url = $baseUrl.'/users/me/balance';

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(10)
            ->retry(1, 250, fn ($e) => $e instanceof ConnectionException)
            ->get($url)
            ->throw();

        $available = $response->json('data.available_balance');
        $amount = is_numeric($available) ? number_format((float) $available, 4, '.', '') : null;
        $ok = is_numeric($available) && (float) $available > 0;

        return new LlmCredits(
            source: self::SOURCE_BALANCE_API,
            available: $ok,
            balance: $amount,
            // api.moonshot.ai is USD; .cn is CNY. Infer from the configured host.
            currency: str_contains($baseUrl, '.cn') ? 'CNY' : 'USD',
            checkedAt: CarbonImmutable::now()->toIso8601ZuluString(),
            detail: $ok ? null : 'available_balance is zero or negative — inference is blocked.',
        );
    }

    private function minimax(string $baseUrl, string $apiKey): LlmCredits
    {
        // Chat under /v1; PAYG balance is /account/query_balance on the origin.
        $url = $this->origin($baseUrl).'/account/query_balance';

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(10)
            ->retry(1, 250, fn ($e) => $e instanceof ConnectionException)
            ->get($url)
            ->throw();

        // MiniMax wraps variously; prefer available_amount, then nested data.
        $amount = $response->json('available_amount')
            ?? $response->json('data.available_amount')
            ?? $response->json('base_resp.available_amount');

        if ($amount === null) {
            // Some keys return a plan quota instead of PAYG balance — treat as
            // "unknown via balance" and let inference cover exhaustion.
            return new LlmCredits(
                source: self::SOURCE_BALANCE_API,
                available: null,
                checkedAt: CarbonImmutable::now()->toIso8601ZuluString(),
                detail: 'Balance response had no available_amount (plan key?).',
            );
        }

        $formatted = is_numeric($amount) ? number_format((float) $amount, 4, '.', '') : (string) $amount;
        $ok = is_numeric($amount) && (float) $amount > 0;

        return new LlmCredits(
            source: self::SOURCE_BALANCE_API,
            available: $ok,
            balance: $formatted,
            currency: 'USD',
            checkedAt: CarbonImmutable::now()->toIso8601ZuluString(),
            detail: $ok ? null : 'available_amount is zero — top up the PAYG balance.',
        );
    }

    /**
     * Prefer a USD row when DeepSeek returns more than one currency.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function preferredBalanceRow(array $rows): array
    {
        foreach ($rows as $row) {
            if (strtoupper((string) ($row['currency'] ?? '')) === 'USD') {
                return $row;
            }
        }

        return $rows[0] ?? [];
    }

    /**
     * Strip a trailing /v1 (or /v1beta) so account endpoints resolve on the origin.
     */
    private function origin(string $baseUrl): string
    {
        return (string) preg_replace('#/v1(?:beta)?/?$#', '', $baseUrl);
    }

    /**
     * No balance endpoint: read the latest failure signals the way an empty
     * account actually presents itself.
     */
    private function inferred(LlmProvider $provider): LlmCredits
    {
        $latest = LlmCallLog::query()
            ->where('llm_provider_id', $provider->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return new LlmCredits(
                source: self::SOURCE_INFERRED,
                available: null,
                detail: 'No calls or probes yet — credit state unknown.',
            );
        }

        $checkedAt = CarbonImmutable::parse($latest->started_at)->toIso8601ZuluString();

        if ($latest->success) {
            return new LlmCredits(
                source: self::SOURCE_INFERRED,
                available: true,
                checkedAt: $checkedAt,
                detail: 'Latest call/probe succeeded — credits appear available.',
            );
        }

        if ($this->looksExhausted((string) $latest->error_code, (string) $latest->error_message)) {
            return new LlmCredits(
                source: self::SOURCE_INFERRED,
                available: false,
                checkedAt: $checkedAt,
                detail: trim(($latest->error_code ?? '').': '.($latest->error_message ?? 'Credits appear exhausted.')),
            );
        }

        return new LlmCredits(
            source: self::SOURCE_INFERRED,
            available: null,
            checkedAt: $checkedAt,
            detail: 'Latest failure does not look like a credit exhaustion ('.$latest->error_code.').',
        );
    }

    private function looksExhausted(string $code, string $message): bool
    {
        if ($code === '402') {
            return true;
        }

        $haystack = strtolower($code.' '.$message);

        foreach (self::EXHAUSTED_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
