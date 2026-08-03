<?php

use Illuminate\Support\Facades\Route;

// API-only backend: the frontend is a separate application. Health check lives
// at /up (framework default). Web routes exist only for genuinely web-served
// pages — e.g. a payment-gateway browser return page, if PGW's card flow needs
// one (pending PGW docs).
Route::get('/', fn () => response()->json([
    'service' => 'Spectrum Global Analytics API',
    'docs' => '/api/v1',
]));
