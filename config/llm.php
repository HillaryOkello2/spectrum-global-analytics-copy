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
        ],
        'moonshot' => [
            'client' => 'openai-compatible',
            'base_url' => env('MOONSHOT_BASE_URL', 'https://api.moonshot.ai/v1'),
            'api_key' => env('MOONSHOT_API_KEY'),
            'max_tokens' => (int) env('LLM_MAX_TOKENS', 16000),
            'timeout' => (int) env('LLM_TIMEOUT', 600),
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
