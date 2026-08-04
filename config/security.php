<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API-only lockdown
    |--------------------------------------------------------------------------
    |
    | This is an API-only backend: the frontend is a separate application on its
    | own domain. Anything the API itself does not serve — the Horizon queue
    | dashboard, the Scribe docs, the storage file route, the root banner — is
    | attack surface on a public host, so it is denied by default in production.
    |
    | RestrictToApi runs as GLOBAL middleware, before routing, so it covers
    | routes registered by packages as well as our own. Requests outside
    | `allowed_paths` get `status` (404 by default) and never reach the router.
    |
    | 404 is deliberate: 403 confirms a path exists and 503 implies it exists but
    | is temporarily down, both of which tell a scanner it found something real.
    | 404 is indistinguishable from a route that was never there.
    |
    | This is defence in depth, not a substitute for gating the dashboards
    | themselves — see HorizonServiceProvider::gate(), which independently denies
    | everyone outside `local`.
    |
    */

    /*
     * ⚠️ TEMPORARY — LOCKDOWN DISABLED (2026-08-04)
     *
     * Turned off while we isolate a connectivity problem between the frontend and
     * this API, so that /, /docs, /horizon and /storage/* are reachable again and
     * one variable is removed from the debugging.
     *
     * TO RESTORE: change the default back to `env('APP_ENV') === 'production'`.
     * Nothing else needs to change — the middleware, tests and allow list are all
     * still in place and still pass. Setting API_ONLY=true in .env re-enables it
     * immediately without a deploy.
     */
    'api_only' => (bool) env('API_ONLY', false),

    'api_only_status' => (int) env('API_ONLY_STATUS', 404),

    /*
    | Paths that stay reachable when the lockdown is on. Values are matched with
    | Request::is(), so `*` wildcards work. Keep this list minimal.
    |
    | `up` is the framework health check — Plesk and uptime monitors hit it, and
    | it returns no application data. Drop it from this list if nothing external
    | needs it.
    */
    'allowed_paths' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('API_ONLY_ALLOWED_PATHS', 'api/*,up'))
    ))),

];
