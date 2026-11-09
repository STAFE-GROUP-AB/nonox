<?php

declare(strict_types=1);

namespace App\Modules\Portal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers on every portal answer (ADR-075 §8), as on the signing page:
 * private and never cached, never indexed, no Referer (invitation tokens
 * are in addresses), not framed, and scripts from NONOX only (no inline
 * script). Set before SecurityHeaders sees the response, which keeps a
 * policy that is already there.
 */
final class PortalHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Referrer-Policy', 'no-referrer');

        if (! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "frame-src 'self'",
                "frame-ancestors 'none'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }
}
