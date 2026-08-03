<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "fake" (local/UAT), "pgw" (company gateway — driver to be
    | implemented once PGW API docs are supplied; see also the existing PGW
    | integration in ~/mcp for reference).
    |
    */

    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    'pgw' => [
        'base_url' => env('PGW_BASE_URL'),
        'api_key' => env('PGW_API_KEY'),
        'callback_secret' => env('PGW_CALLBACK_SECRET'),
    ],

];
