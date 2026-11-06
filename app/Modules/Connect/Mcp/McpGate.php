<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mcp;

use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\Problem;
use App\Support\Audit\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the MCP endpoint asks of a credential on top of NONOX API's own
 * checks (ADR-063), after AuthenticateConnect and ConnectRateLimit:
 *
 * - Only read and draft scopes (McpTools::ALLOWED_SCOPES). A key or app
 *   that may also post, correct, send, credit, pay, approve, export or
 *   manage is refused with 403 `mcp_scope_not_allowed`, so the key an AI
 *   assistant holds cannot do any of that over REST either.
 * - Its own limit per credential and minute for MCP messages (default 60),
 *   inside the API's shared limits.
 */
final class McpGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $credential = ConnectAccess::current();

        if ($credential === null) {
            return Problem::unauthenticated();
        }

        $extra = array_values(array_diff($credential->scopes, McpTools::ALLOWED_SCOPES));

        if ($extra !== []) {
            Audit::record('mcp.credential_refused', null, [
                'reason' => 'scope_not_allowed',
                'scopes' => $extra,
                'credential' => ['kind' => $credential->kind, 'id' => $credential->id],
                'request_id' => $request->attributes->get('connect.request_id'),
            ], $credential->company, $credential->user);

            return Problem::make(403, 'mcp_scope_not_allowed', __('En nyckel eller app för en AI-assistent får bara kunna läsa och skapa utkast. Skapa en egen nyckel med bara de behörigheterna.'), [
                'scopes_not_allowed' => $extra,
                'allowed_scopes' => McpTools::ALLOWED_SCOPES,
            ]);
        }

        $key = 'connect:mcp:'.$credential->key();
        $limit = max(1, (int) config('services.connect.mcp_rate_limit_per_credential', 60));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            return Problem::make(429, 'rate_limited', __('För många anrop. Försök igen om :seconds sekunder.', ['seconds' => $retryAfter]), [], [
                'Retry-After' => (string) $retryAfter,
            ]);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
