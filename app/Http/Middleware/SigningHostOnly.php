<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Signing\SigningHost;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request to the instance's signing host (config nonox.signing.host) reaches
 * the signing pages and nothing else, and every address built while answering
 * it uses that host, so the form posts back to it (ADR-046, S20). Other hosts
 * are untouched, and old links on the application's own address keep working.
 */
final class SigningHostOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = SigningHost::host();

        if ($host === null || strtolower($request->getHost()) !== $host) {
            return $next($request);
        }

        abort_unless(SigningHost::allows(ltrim($request->path(), '/')), 404);

        URL::forceRootUrl((string) SigningHost::root());

        try {
            return $next($request);
        } finally {
            URL::forceRootUrl(null);
        }
    }
}
