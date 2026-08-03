<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FIFO release
    |--------------------------------------------------------------------------
    |
    | Approved products are not published the moment a proofreader signs them
    | off. They join a queue and go live oldest-approval-first, so the catalogue
    | fills at a steady, editorially controlled pace rather than in bursts.
    |
    | `batch` is how many products each scheduler run takes live. The default is
    | a placeholder pending the client's preferred release pace — it is
    | env-driven so it can be tuned without a deploy.
    |
    */

    'release_batch' => (int) env('PUBLISHING_RELEASE_BATCH', 5),

    'release_cron' => env('PUBLISHING_RELEASE_CRON', '0 * * * *'),

];
