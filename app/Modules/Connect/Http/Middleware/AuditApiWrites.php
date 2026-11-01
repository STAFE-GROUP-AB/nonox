<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Middleware;

use App\Modules\Connect\Support\ConnectAccess;
use App\Support\Audit\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every write through the API ends up in the audit log (ADR-032), whether it
 * succeeded or not: which app or key, which person, which endpoint, the
 * Idempotency-Key and the status. The Actions log what they changed on top
 * of this, as they do from the UI.
 */
final class AuditApiWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $credential = ConnectAccess::current();

        if ($request->isMethodSafe() || $credential === null) {
            return $response;
        }

        Audit::record('api.request', null, [
            'method' => $request->method(),
            'route' => $request->route()?->uri(),
            'path' => '/'.$request->path(),
            'status' => $response->getStatusCode(),
            'credential' => $credential->describe(),
            'idempotency_key' => $request->headers->get(RequireIdempotencyKey::HEADER),
            'replayed' => $response->headers->get('Idempotent-Replayed') === 'true',
            'request_id' => $request->attributes->get('connect.request_id'),
        ], $credential->company, $credential->user);

        return $response;
    }
}
