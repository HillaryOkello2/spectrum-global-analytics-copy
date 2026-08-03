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
    */

    'fake' => env('LLM_FAKE', env('APP_ENV') !== 'production'),

    'drivers' => [
        'anthropic' => [
            'client' => 'anthropic',
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'api_key' => env('ANTHROPIC_API_KEY'),
        ],
        'gemini' => [
            'client' => 'gemini',
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'api_key' => env('GEMINI_API_KEY'),
        ],
        'openai' => [
            'client' => 'openai-compatible',
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
        ],
        'deepseek' => [
            'client' => 'openai-compatible',
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'api_key' => env('DEEPSEEK_API_KEY'),
        ],
        'moonshot' => [
            'client' => 'openai-compatible',
            'base_url' => env('MOONSHOT_BASE_URL', 'https://api.moonshot.ai/v1'),
            'api_key' => env('MOONSHOT_API_KEY'),
        ],
        'minimax' => [
            'client' => 'openai-compatible',
            'base_url' => env('MINIMAX_BASE_URL', 'https://api.minimax.io/v1'),
            'api_key' => env('MINIMAX_API_KEY'),
        ],
    ],

];
