<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "fake" (local/UAT: every callback is trusted, so never in
    | production) and "pgw" (TechBiz's PGW: M-Pesa STK push and hosted card
    | checkout).
    |
    */

    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Charge currency
    |--------------------------------------------------------------------------
    |
    | Tiers are priced in USD. PGW settles in KES and M-Pesa takes whole
    | shillings only, so with PGW set PAYMENT_CHARGE_CURRENCY=KES: list prices
    | are converted at the rate below and rounded up to a whole shilling. Unset,
    | the list price is charged in the tier's own currency.
    |
    | Rates are keyed FROM_TO. Keep them current: the rate is what customers pay.
    |
    */

    'charge_currency' => env('PAYMENT_CHARGE_CURRENCY'),

    'exchange_rates' => [
        'USD_KES' => env('PAYMENT_USD_KES_RATE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pending timeout
    |--------------------------------------------------------------------------
    |
    | Minutes a payment may stay pending before payments:expire-pending fails
    | it. PGW calls back on success only, so a declined or ignored M-Pesa
    | prompt is never reported: this timeout is what frees it for a retry.
    |
    */

    'pending_timeout' => (int) env('PAYMENT_PENDING_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | PGW
    |--------------------------------------------------------------------------
    |
    | Credentials belong to Spectrum's own PGW merchant account and come from
    | the environment only. Never reuse another project's merchant keys: the
    | money would settle into that merchant's account.
    |
    */

    'pgw' => [
        'base_url' => env('PGW_BASE_URL'),
        'merchant_key' => env('PGW_MERCHANT_KEY'),
        'merchant_secret' => env('PGW_MERCHANT_SECRET'),
        'account_id' => env('PGW_ACCOUNT_ID'),

        // How M-Pesa is collected. "checkout" hands the payer PGW's hosted
        // page, which asks for the number and sends the STK push itself — the
        // flow proven against payments.techbizafrica.com. "stk" pushes from
        // here via Token + MStk instead, which needs MStk enabled for the
        // merchant account and gives the payer no redirect.
        'mpesa_mode' => env('PGW_MPESA_MODE', 'checkout'),

        // PGW authenticates its callbacks with these, sent as
        // `Authorization: Bearer base64(key:secret)`. Some merchants are issued
        // a dedicated pair; where none is, PGW signs with the merchant
        // credential, so that is what these fall back to.
        'callback_key' => env('PGW_CALLBACK_KEY') ?: env('PGW_MERCHANT_KEY'),
        'callback_secret' => env('PGW_CALLBACK_SECRET') ?: env('PGW_MERCHANT_SECRET'),

        // Defaults to this app's webhook route. Override to point PGW at a
        // tunnel while testing against the sandbox.
        'callback_url' => env('PGW_CALLBACK_URL'),

        // An unguessable string of our own, added to the callback URL we hand
        // PGW and required back on every callback. PGW's own sample receiver
        // authenticates nothing, so where no callback credential is issued this
        // is what proves a callback came from the URL we gave them. Generate
        // one with `php artisan str:random 40`.
        'callback_token' => env('PGW_CALLBACK_TOKEN'),

        // Optional allowlist of PGW's callback source IPs, comma-separated.
        'callback_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PGW_CALLBACK_IPS', '')),
        ))),

        'order_prefix' => env('PGW_ORDER_PREFIX', 'SGA-'),
        'timeout' => (int) env('PGW_TIMEOUT', 30),
    ],

];
