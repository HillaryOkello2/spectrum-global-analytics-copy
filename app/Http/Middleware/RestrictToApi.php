<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Denies everything that is not the API itself (§ config/security.php).
 *
 * Registered globally so it runs BEFORE routing — that is what lets it cover
 * package-registered routes (Horizon's dashboard, Scribe's docs, the storage
 * file route) without having to find and disable each one separately, and what
 * keeps a route added later from being exposed by default.
 */
class RestrictToApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.api_only') || $this->isAllowed($request)) {
            return $next($request);
        }

        return self::blocked();
    }

    /**
     * The blocked response: bare status, no body, nothing that identifies the
     * stack. An error page — even the framework's generic one — would tell a
     * scanner what is running here.
     */
    public static function blocked(): Response
    {
        $response = response('', config('security.api_only_status'));

        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        // PHP appends its own `X-Powered-By: PHP/8.3.x` outside Symfony's header
        // bag when expose_php is on, leaking the exact patch version. The real
        // fix is `expose_php = Off` in php.ini; this covers hosts where that is
        // not ours to set. `Server:` is added by nginx/Apache after PHP hands the
        // response back and can only be removed there (`server_tokens off`).
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        return $response;
    }

    private function isAllowed(Request $request): bool
    {
        $allowed = config('security.allowed_paths', []);

        return $allowed !== [] && $request->is(...$allowed);
    }
}
