<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mcp;

use App\Modules\Connect\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first check on /api/mcp (ADR-063), before anything parses the body:
 * one JSON-RPC message is small, so a body over 256 kB is refused with 413,
 * and JSON nested deeper than 32 levels with 400. No tool argument needs
 * more; the limits keep a client from making the server parse megabytes or
 * deep structures before it is even authenticated.
 */
final class LimitMcpBody
{
    public const int MAX_BYTES = 262144;

    public const int MAX_DEPTH = 32;

    public function handle(Request $request, Closure $next): Response
    {
        $declared = $request->headers->get('Content-Length');

        if (is_numeric($declared) && (int) $declared > self::MAX_BYTES) {
            return Problem::make(413, 'payload_too_large', __('Begäran är för stor.'));
        }

        $content = (string) $request->getContent();

        if (strlen($content) > self::MAX_BYTES) {
            return Problem::make(413, 'payload_too_large', __('Begäran är för stor.'));
        }

        if ($content !== '') {
            json_decode($content, true, self::MAX_DEPTH);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return Problem::make(400, 'invalid_json', __('Kroppen är inte giltig JSON (ett objekt).'));
            }
        }

        return $next($request);
    }
}
