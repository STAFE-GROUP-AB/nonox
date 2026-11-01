<?php

declare(strict_types=1);

/*
 * Trusted proxies (security review 2026-11-01). Off by default: the client's
 * address is the one that connects, and X-Forwarded-* headers are ignored.
 *
 * Behind a proxy or CDN (Cloudflare with the orange cloud, a load balancer),
 * every visitor would otherwise share the proxy's address, and the per-IP
 * limits on sign-in codes would hit everyone at once. List the proxy's
 * address ranges, comma separated, in TRUSTED_PROXIES; requests from them
 * are then believed about the client's address. Never use "*" on a server
 * that can be reached without the proxy: anyone could then pick their own
 * address. See README.md, "Köra NONOX på en server".
 *
 * Laravel's TrustProxies middleware reads this file on every request. The
 * documented ->trustProxies(at: ...) call in bootstrap/app.php runs before
 * .env is loaded, so it cannot take the list from there.
 */
return [
    // null keeps Laravel's own default; "*" is passed on as the string Laravel expects.
    'proxies' => (static function (): array|string|null {
        $proxies = array_values(array_filter(array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', '')))));

        return match (true) {
            $proxies === [] => null,
            $proxies === ['*'] => '*',
            default => $proxies,
        };
    })(),
];
