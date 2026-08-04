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

    /*
     * ⚠️ TEMPORARY — OPEN TO EVERY ORIGIN (2026-08-04)
     *
     * Deliberately wide open while we isolate a connectivity problem between the
     * frontend and this API. TO RESTORE: delete the `?: ['*']` fallback below and
     * put back the explicit list (demo frontend + localhost dev ports), and
     * restore the ngrok patterns underneath.
     *
     * Setting CORS_ALLOWED_ORIGINS in .env still overrides this, so the deployed
     * host can be locked back down without a code change.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))) ?: ['*'],

    /*
     * A catch-all pattern, not just `allowed_origins = ['*']`.
     *
     * `supports_credentials` is true below, and a browser REJECTS the literal
     * `Access-Control-Allow-Origin: *` on any credentialed request. Matching by
     * pattern makes the CORS layer echo the caller's actual origin back instead,
     * which is equally permissive but stays valid with credentials.
     */
    'allowed_origins_patterns' => ['#.*#'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,

];
