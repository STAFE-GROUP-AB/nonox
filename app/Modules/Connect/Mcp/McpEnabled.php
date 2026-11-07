<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mcp;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The very first check on /api/mcp: the endpoint answers 404, as if it did
 * not exist, until `nonox.mcp_enabled` (NONOX_MCP_ENABLED) is on. Off by
 * default (owner decision 18): it is switched on only when the privacy
 * policy names AI providers as recipients.
 */
final class McpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('nonox.mcp_enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
