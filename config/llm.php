<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LLM Provider Drivers
    |--------------------------------------------------------------------------
    |
    | One entry per llm_providers.driver value. `client` selects the HTTP driver
    | implementation. Set LLM_FAKE=true to route every provider to the fake
    | client (local dev / CI — no API keys or network needed).
    |
    | `max_tokens` is the output ceiling. It has to be generous: these documents
    | are long-form, and on a reasoning model the budget is shared with the
    | thinking tokens — too low and the model spends the whole allowance
    | thinking and returns no text at all. Defaults are per-vendor because the
    | hard ceilings differ; LLM_MAX_TOKENS overrides all of them.
    |
    | `timeout` is likewise per-request, not per-run: a 56-page Research Paper
    | is minutes of streaming, not seconds.
    |
    */

    'fake' => env('LLM_FAKE', env('APP_ENV') !== 'production'),

    /*
    | What verdict the fake driver's QA auditor returns. The verdict is acted
    | on now (config/qa.php), so the stub has to be able to produce a failing
    | one or the gate cannot be exercised without spending credit.
    |
    |   green     — always passes (default)
    |   red       — always fails: drives the gate through to `qa_failed`
    |   first_red — fails the first draft, passes the rewrite: drives the whole
    |               QA → revision → re-audit loop
    */

    'fake_qa_verdict' => env('LLM_FAKE_QA_VERDICT', 'green'),

    /*
    | The per-request HTTP timeout every driver below defaults to, kept at the
    | top level as well so the queue jobs can give themselves a longer leash
    | than the call they are waiting on. A worker whose timeout is shorter than
    | the HTTP call SIGKILLs itself mid-generation — exit 137, credit spent,
    | nothing written.
    */

    'timeout' => (int) env('LLM_TIMEOUT', 600),

    'drivers' => [
        'anthropic' => [
            'client' => 'anthropic',
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'max_tokens' => (int) env('LLM_MAX_TOKENS', 32000),
            'timeout' => (int) env('LLM_TIMEOUT', 600),
            // Reasoning is off by default. On Sonnet 5 it is on unless asked
            // otherwise, shares the max_tokens budget with the answer, and has
            // no cap of its own — a long document prompt can spend the entire
            // allowance thinking and come back with no text at all.
            // 'adaptive' re-enables it, bounded by output_config.effort.
            'thinking' => env('ANTHROPIC_THINKING', 'disabled'),
            'effort' => env('ANTHROPIC_EFFORT', 'medium'),
        ],
        'gemini' => [
            'client' => 'gemini',
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'api_key' => env('GEMINI_API_KEY'),
            'max_tokens' => (int) env('LLM_MAX_TOKENS', 32000),
            'timeout' => (int) env('LLM_TIMEOUT', 600),
            // Gemini thinks by default and pays for it out of maxOutputTokens,
            // like DeepSeek and Kimi. Which control works depends on the model:
            // 3.6-flash takes thinkingLevel "minimal" (thinking fully off) but
            // rejects a budget of 0; 3.7-flash rejects "minimal", ignores a
            // budget of 0, and honours only a positive budget as a cap. The
            // level wins when both are set. See GeminiClient::thinkingOptions().
            'thinking_level' => env('GEMINI_THINKING_LEVEL', 'minimal'),
            'thinking_budget' => env('GEMINI_THINKING_BUDGET'),
        ],
        'openai' => [
            'client' => 'openai-compatible',
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            'max_tokens' => (int) env('LLM_MAX_TOKENS', 16000),
            'timeout' => (int) env('LLM_TIMEOUT', 600),
        ],
        'deepseek' => [
            'client' => 'openai-compatible',
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'api_key' => env('DEEPSEEK_API_KEY'),
            'max_tokens' => (int) env('LLM_MAX_TOKENS', 8000),
            'timeout' => (int) env('LLM_TIMEOUT', 600),
            // DeepSeek reasons by default, and pays for it out of max_tokens:
            // V4 Pro spent all 8,000 on a CC brief without writing a word
            // (2026-09-10), and was billed for them. Off by default; set
            // 'enabled' to turn it back on.
            'thinking' => env('DEEPSEEK_THINKING', 'disabled'),
        ],
        'moonshot' => [
            'client' => 'openai-compatible',
            'base_url' => env('MOONSHOT_BASE_URL', 'https://api.moonshot.ai/v1'),
            'api_key' => env('MOONSHOT_API_KEY'),
            'max_tokens' => (int) env('LLM_MAX_TOKENS', 16000),
            'timeout' => (int) env('LLM_TIMEOUT', 600),
            // Same trap as DeepSeek, and the same switch: Kimi K2.6 spent 15,999
            // of 16,000 tokens reasoning on an ES essay and wrote nothing
            // (2026-09-10). Off by default; set 'enabled' to turn it back on.
            'thinking' => env('MOONSHOT_THINKING', 'disabled'),
        ],
        'minimax' => [
            'client' => 'openai-compatible',
            'base_url' => env('MINIMAX_BASE_URL', 'https://api.minimax.io/v1'),
            'api_key' => env('MINIMAX_API_KEY'),
            'max_tokens' => (int) env('LLM_MAX_TOKENS', 16000),
            'timeout' => (int) env('LLM_TIMEOUT', 600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health monitoring
    |--------------------------------------------------------------------------
    |
    | Backs GET /admin/analytics/llm-health. Every number on that dashboard
    | comes from llm_call_logs, written by LlmCallRecorder around each attempt
    | — there is no other record that a model was ever called.
    |
    | The thresholds below are the documented status rules. They are env-driven
    | because the right numbers are a matter of what the vendors actually do,
    | and that is learned in production, not decided here.
    |
    */

    'health' => [

        // The dashboard polls every 30s; a short cache keeps a burst of admins
        // off the aggregate queries without the panel ever looking stale.
        'cache_seconds' => (int) env('LLM_HEALTH_CACHE_SECONDS', 15),

        // The rolling window every count on the dashboard is taken over.
        'window_hours' => (int) env('LLM_HEALTH_WINDOW_HOURS', 24),

        // Most recent failures returned per provider.
        'recent_errors' => (int) env('LLM_HEALTH_RECENT_ERRORS', 20),

        // Floor is 7 days: the degraded-latency rule compares the window
        // against the provider's own 7-day average.
        'retention_days' => (int) env('LLM_CALL_LOG_RETENTION_DAYS', 30),

        // `down`: this many consecutive real calls failed, newest first.
        'down_consecutive_failures' => (int) env('LLM_HEALTH_DOWN_FAILURES', 5),

        // `degraded`: share of failed calls in the window, 0.10 = 10%.
        'degraded_error_rate' => (float) env('LLM_HEALTH_DEGRADED_ERROR_RATE', 0.10),

        // `degraded`: window latency this many times the 7-day average.
        'degraded_latency_multiple' => (float) env('LLM_HEALTH_DEGRADED_LATENCY_MULTIPLE', 2.0),

        // Below these sample sizes the latency comparison is noise, not signal:
        // two slow calls on a quiet provider are not a degradation.
        'latency_min_window_calls' => (int) env('LLM_HEALTH_LATENCY_MIN_WINDOW', 3),
        'latency_min_baseline_calls' => (int) env('LLM_HEALTH_LATENCY_MIN_BASELINE', 10),

        'probe' => [
            // Scheduled llm:probe. Off in local/testing by default — there is
            // nothing to learn from probing the fake driver on a timer.
            'enabled' => (bool) env('LLM_PROBE_ENABLED', env('APP_ENV') === 'production'),

            // One token of output, on the shortest prompt that still proves the
            // credentials and the endpoint are good.
            'prompt' => env('LLM_PROBE_PROMPT', 'Reply with: OK'),
            'max_tokens' => (int) env('LLM_PROBE_MAX_TOKENS', 5),

            // Deliberately short. A probe is a liveness check; a provider that
            // needs 10 minutes to emit one token is not live.
            'timeout' => (int) env('LLM_PROBE_TIMEOUT', 30),
        ],
    ],

];
