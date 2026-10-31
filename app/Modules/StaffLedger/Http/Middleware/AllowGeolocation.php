<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile check-in reads the phone's position, which the app's default
 * Permissions-Policy forbids. This page alone allows it for its own origin.
 */
class AllowGeolocation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self), payment=(), usb=(), interest-cohort=()');

        return $response;
    }
}
