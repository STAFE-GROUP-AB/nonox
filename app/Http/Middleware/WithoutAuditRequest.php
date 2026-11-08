<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Audit\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the public signing page (ADR-046) every audit entry, also those the
 * follow-up writes when the queue runs in the request, is written without
 * the visitor's IP address and user agent. The signer's IP address is kept
 * only as evidence on the signing request and its SIGNED event.
 */
final class WithoutAuditRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        return Audit::withoutRequest(fn (): Response => $next($request));
    }
}
