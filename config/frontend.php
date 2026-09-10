<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend origins
    |--------------------------------------------------------------------------
    |
    | This backend is API-only, so every link it emails — sign-in, password
    | reset — has to point at a frontend app. Staff and subscribers sign in to
    | different apps, so each origin is configured separately and the right one
    | is chosen per recipient (see App\Services\Access\FrontendLinks).
    |
    | The paths are routes in the frontend apps, not in this application.
    | Override them if the frontend names its pages differently.
    |
    */

    'admin_url' => env('FRONTEND_ADMIN_URL', 'http://localhost:3002'),

    'subscriber_url' => env('FRONTEND_SUBSCRIBER_URL', 'http://localhost:3001'),

    'paths' => [
        'login' => env('FRONTEND_LOGIN_PATH', '/login'),
        'reset_password' => env('FRONTEND_RESET_PASSWORD_PATH', '/reset-password'),
    ],

];
