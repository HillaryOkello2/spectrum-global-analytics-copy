<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Controls which browser origins may call the API. Applied by the framework's
    | HandleCors middleware to the paths below.
    |
    | Origins are explicit (never "*") so that credentialed requests
    | (cookies / withCredentials) are allowed — browsers reject the "*" wildcard
    | when credentials are involved. Override per-environment with the
    | CORS_ALLOWED_ORIGINS env var (comma-separated), e.g. your production
    | frontend URL.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))) ?: [
        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:3002',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'http://127.0.0.1:3002',
        'https://7795-2605-59c1-44fd-f14-197d-8d97-22a3-80d9.ngrok-free.app',
        'http://192.168.2.108:3000',
        'http://192.168.2.108:3001',
        'http://192.168.2.108:3002',
    ],

    // Regex patterns for origins whose exact URL changes between runs — notably
    // ngrok free tunnels, which get a new subdomain each restart. Matches any
    // ngrok origin so CORS keeps working without editing allowed_origins above.
    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+\.ngrok-free\.app$#',
        '#^https://[a-z0-9-]+\.ngrok\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,

];
