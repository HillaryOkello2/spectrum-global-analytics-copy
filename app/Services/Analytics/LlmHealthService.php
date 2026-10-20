<?php

namespace App\Services\Analytics;

use App\Models\LlmCallLog;
use App\Models\LlmProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The snapshot behind GET /admin/analytics/llm-health.
 *
 * Every figure is read from llm_call_logs, which LlmCallRecorder writes one row
 * of per attempt. Statuses are decided here, not in the frontend — the rules
 * are documented in docs/API_REFERENCE.md and implemented in statusFor().
 */
class LlmHealthService
{
    private const CACHE_KEY = 'analytics:llm-health';

    /**
     * Non-numeric error codes that mean the provider itself is unavailable or
     * is refusing us, as opposed to one bad request. A probe failing with one
     * of these is what makes a provider with no traffic read as `down` rather
     * than `idle`. See isOutageCode() for the numeric ones.
     *
     * @var array<int, string>
     */
    private const OUTAGE_CODES = ['timeout', 'network', 'unknown'];

    /**
     * @return array{generatedAt: string, providers: array<int, array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $seconds = (int) config('llm.health.cache_seconds', 15);

        if ($seconds <= 0) {
            return $this->compute();
        }

        return Cache::remember(self::CACHE_KEY, $seconds, fn () => $this->compute());
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{generatedAt: string, providers: array<int, array<string, mixed>>}
     */
    private function compute(): array
    {
        $now = CarbonImmutable::now();
        $windowFrom = $now->subHours((int) config('llm.health.window_hours', 24));
        $baselineFrom = $now->subDays(7);

        // Every provider, including inactive ones and ones that have never
        // been called: the dashboard draws one node per provider and a missing
        // node reads as "fine", which is the opposite of the truth.
        $providers = LlmProvider::query()->orderBy('id')->get();

        $totals = $this->totals($windowFrom);
        $baseline = $this->latencyBaseline($baselineFrom);

        return [
            'generatedAt' => $now->toIso8601ZuluString(),
            'providers' => $providers
                ->map(fn (LlmProvider $provider) => $this->describe(
                    $provider,
                    $totals->get($provider->id),
                    $baseline->get($provider->id),
                    $windowFrom,
                ))
                ->all(),
        ];
    }

    /**
     * Counts over the window, grouped by provider in one pass. Probes are
     * excluded here and nowhere else — §2.2 requires requests24h and errors24h
     * to be real traffic only.
     *
     * @return Collection<int, object>
     */
    private function totals(CarbonImmutable $from): Collection
    {
        return LlmCallLog::query()
            ->real()
            ->since($from)
            ->groupBy('llm_provider_id')
            ->select('llm_provider_id')
            ->selectRaw('count(*) as requests')
            ->selectRaw('sum(case when success = 1 then 0 else 1 end) as errors')
            ->selectRaw('avg(case when success = 1 then latency_ms end) as avg_latency')
            ->selectRaw('sum(case when success = 1 then 1 else 0 end) as successes')
            ->get()
            ->keyBy('llm_provider_id');
    }

    /**
     * Each provider's own 7-day mean latency, which the window is compared
     * against. A provider is judged against itself: 4s is healthy for a
     * long-form model and a red flag for a one-token probe target.
     *
     * @return Collection<int, object>
     */
    private function latencyBaseline(CarbonImmutable $from): Collection
    {
        return LlmCallLog::query()
            ->real()
            ->since($from)
            ->where('success', true)
            ->groupBy('llm_provider_id')
            ->select('llm_provider_id')
            ->selectRaw('avg(latency_ms) as avg_latency')
            ->selectRaw('count(*) as calls')
            ->get()
            ->keyBy('llm_provider_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(
        LlmProvider $provider,
        ?object $totals,
        ?object $baseline,
        CarbonImmutable $windowFrom,
    ): array {
        $requests = (int) ($totals->requests ?? 0);
        $errors = (int) ($totals->errors ?? 0);
        $avgLatency = $totals?->avg_latency === null ? null : (int) round((float) $totals->avg_latency);

        $recent = $this->recentCalls($provider, $windowFrom);
        $latestProbe = $this->latestProbe($provider, $windowFrom);
        $lastCheckedAt = $this->lastCheckedAt($provider);

        return [
            'publicId' => $provider->public_id,
            'name' => $provider->name,
            'vendor' => $provider->vendor,
            'status' => $this->statusFor(
                $provider,
                $requests,
                $errors,
                $avgLatency,
                (int) ($totals->successes ?? 0),
                $baseline,
                $recent,
                $latestProbe,
            ),
            'requests24h' => $requests,
            'errors24h' => $errors,
            'avgLatencyMs' => $avgLatency,
            'lastCheckedAt' => $lastCheckedAt?->toIso8601ZuluString(),
            'recentErrors' => $this->recentErrors($provider, $windowFrom),
        ];
    }

    /**
     * The status rules, in priority order. Documented in the API reference;
     * change them there as well as here.
     */
    private function statusFor(
        LlmProvider $provider,
        int $requests,
        int $errors,
        ?int $avgLatency,
        int $successes,
        ?object $baseline,
        EloquentCollection $recent,
        ?LlmCallLog $latestProbe,
    ): string {
        $consecutive = (int) config('llm.health.down_consecutive_failures', 5);

        // 1. down — the last N real calls all failed. N must actually have
        //    happened: one failure on an otherwise quiet provider is a
        //    degradation, not an outage, and the probe below is what catches
        //    a real outage on a provider nobody is calling.
        if ($recent->count() >= $consecutive && $recent->every(fn (LlmCallLog $call) => ! $call->success)) {
            return 'down';
        }

        // 2. down — the latest probe failed on credentials or availability and
        //    nothing has succeeded since. This is the rule that turns a
        //    provider red within one probe interval when its key is revoked.
        $probeFailing = $latestProbe !== null
            && ! $latestProbe->success
            && ! $this->succeededSince($provider, $latestProbe->started_at);

        if ($probeFailing && $this->isOutageCode((string) $latestProbe->error_code)) {
            return 'down';
        }

        // 3. degraded — failures at a rate worth looking at, or the provider
        //    answering far slower than it normally does.
        if ($requests > 0 && ($errors / $requests) >= (float) config('llm.health.degraded_error_rate', 0.10)) {
            return 'degraded';
        }

        if ($this->latencyDegraded($avgLatency, $successes, $baseline)) {
            return 'degraded';
        }

        // A probe failing for a reason that is not an outage — a 429, a 400
        // from a model id the vendor has retired — still means something is
        // wrong. Falling through to `healthy` on the strength of no traffic
        // would be the dashboard's worst possible answer.
        if ($probeFailing) {
            return 'degraded';
        }

        // 4. idle — nothing called it, and no probe says otherwise. A switched
        //    off provider lands here: it is not probed, so it has no failing
        //    probe to be `down` for.
        if ($requests === 0 && ($latestProbe === null || $latestProbe->success)) {
            return 'idle';
        }

        return 'healthy';
    }

    /**
     * Whether an error code means the provider is unavailable to us rather
     * than one request being bad: the auth and billing refusals (401, 402,
     * 403), anything 5xx, and the transport failures.
     */
    private function isOutageCode(string $code): bool
    {
        if (in_array($code, self::OUTAGE_CODES, true)) {
            return true;
        }

        if (! ctype_digit($code)) {
            return false;
        }

        $status = (int) $code;

        return in_array($status, [401, 402, 403], true) || $status >= 500;
    }

    /**
     * Slower than ~2× its own 7-day average, with enough calls on both sides
     * for the comparison to mean anything.
     */
    private function latencyDegraded(?int $avgLatency, int $successes, ?object $baseline): bool
    {
        if ($avgLatency === null || $baseline === null || $baseline->avg_latency === null) {
            return false;
        }

        if ($successes < (int) config('llm.health.latency_min_window_calls', 3)
            || (int) $baseline->calls < (int) config('llm.health.latency_min_baseline_calls', 10)) {
            return false;
        }

        return $avgLatency > (float) $baseline->avg_latency
            * (float) config('llm.health.degraded_latency_multiple', 2.0);
    }

    /**
     * The newest real calls, newest first, capped at the `down` threshold —
     * all this rule needs to know is whether every one of them failed.
     *
     * @return EloquentCollection<int, LlmCallLog>
     */
    private function recentCalls(LlmProvider $provider, CarbonImmutable $from): EloquentCollection
    {
        return LlmCallLog::query()
            ->where('llm_provider_id', $provider->id)
            ->real()
            ->since($from)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit((int) config('llm.health.down_consecutive_failures', 5))
            ->get();
    }

    private function latestProbe(LlmProvider $provider, CarbonImmutable $from): ?LlmCallLog
    {
        return LlmCallLog::query()
            ->where('llm_provider_id', $provider->id)
            ->where('is_probe', true)
            ->since($from)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    private function succeededSince(LlmProvider $provider, \DateTimeInterface $after): bool
    {
        return LlmCallLog::query()
            ->where('llm_provider_id', $provider->id)
            ->where('success', true)
            ->where('started_at', '>', $after)
            ->exists();
    }

    /**
     * Real call or probe, whichever is newer — "when did we last hear from
     * this provider at all".
     */
    private function lastCheckedAt(LlmProvider $provider): ?CarbonImmutable
    {
        $latest = LlmCallLog::query()
            ->where('llm_provider_id', $provider->id)
            ->max('started_at');

        return $latest === null ? null : CarbonImmutable::parse($latest);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentErrors(LlmProvider $provider, CarbonImmutable $from): array
    {
        return LlmCallLog::query()
            ->where('llm_provider_id', $provider->id)
            ->where('success', false)
            ->since($from)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit((int) config('llm.health.recent_errors', 20))
            ->get()
            ->map(fn (LlmCallLog $call) => [
                'occurredAt' => CarbonImmutable::parse($call->started_at)->toIso8601ZuluString(),
                'code' => (string) ($call->error_code ?? 'unknown'),
                // Already sanitised and truncated on the way in; nothing here
                // can re-introduce a key or a prompt body.
                'message' => (string) ($call->error_message ?? 'Unknown error.'),
                'context' => $call->context,
            ])
            ->all();
    }
}
