<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every web response. The content security policy is
 * limited to what does not break Livewire and Alpine (which need inline
 * scripts and eval): no plugins, no foreign base or form targets, and no
 * framing of pages. Files (PDFs, images) may be framed by NONOX's own pages,
 * which show underlag, invoices and reports beside the form; a response
 * that sets its own policy keeps it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        // Framing is limited by frame-ancestors in the CSP; X-Frame-Options (SAMEORIGIN) is left to the web server.
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // A page that needs a device feature (the staff ledger kiosk's camera, mobile check-in's location) sets its own, narrower policy.
        if (! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        }
        if (! $headers->has('Content-Security-Policy')) {
            $isPage = str_starts_with(strtolower((string) $headers->get('Content-Type', 'text/html')), 'text/html');
            $framing = $isPage ? "'none'" : "'self'";
            $headers->set('Content-Security-Policy', "frame-ancestors {$framing}; object-src 'none'; base-uri 'self'; form-action 'self'");
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
