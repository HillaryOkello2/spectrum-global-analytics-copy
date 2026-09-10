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

];
