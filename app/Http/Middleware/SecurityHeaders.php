<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every web response. The content security policy
 * allows only NONOX's own origin for every kind of resource (see policy()),
 * no plugins, no foreign base or form targets, and no framing of pages. Files (PDFs, images) may be framed by NONOX's own pages,
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
        // A page that must not leak its address (the public signing page, ADR-046) sets its own, stricter policy.
        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        // A page that needs a device feature (the staff ledger kiosk's camera, mobile check-in's location) sets its own, narrower policy.
        if (! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        }
        if (! $headers->has('Content-Security-Policy')) {
            $isPage = str_starts_with(strtolower((string) $headers->get('Content-Type', 'text/html')), 'text/html');
            $framing = $isPage ? "'none'" : "'self'";
            $headers->set('Content-Security-Policy', self::policy($framing));
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Everything is loaded from NONOX itself: scripts, styles, fonts and
     * images, and the browser may only talk to NONOX. Injected markup can
     * therefore neither load a foreign script nor send data elsewhere. Inline
     * scripts and eval stay allowed for Livewire and Alpine. While Vite's dev
     * server runs (local only) its address is allowed too.
     */
    private static function policy(string $framing): string
    {
        $self = "'self'";
        $connect = $self;

        // CSP cannot name an IPv6 address like Vite's [::1], so the local dev server gets its schemes.
        if (app()->environment('local') && Vite::isRunningHot()) {
            $self .= ' http: https:';
            $connect = "{$self} ws: wss:";
        }

        return implode('; ', [
            "default-src {$self}",
            "script-src {$self} 'unsafe-inline' 'unsafe-eval'",
            "style-src {$self} 'unsafe-inline'",
            "img-src {$self} data: blob:",
            "font-src {$self} data:",
            "connect-src {$connect}",
            "media-src {$self} blob:",
            "worker-src {$self} blob:",
            "frame-src {$self} blob:",
            "frame-ancestors {$framing}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
