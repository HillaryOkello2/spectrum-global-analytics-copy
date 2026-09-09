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

    /*
    |--------------------------------------------------------------------------
    | Redaction pass
    |--------------------------------------------------------------------------
    |
    | The second review stage, where a redacted copy of the document is written
    | for subscribers whose tier withholds the full one. It is a commercial
    | device, not a security one: without a redaction, a denied or quota-
    | exhausted subscriber sees only the abstract.
    |
    | Off for now — proofreading approves the product directly. Turning this
    | back on re-inserts `awaiting_redaction` between proofreading and approval;
    | nothing else needs to change, and products approved while it was off keep
    | working (they simply have no redacted copy to serve).
    |
    */

    'redaction' => (bool) env('PUBLISHING_REDACTION', false),

];
