<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessions;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Switches a guest's whole request to the demo database (ADR-077, addendum
 * 2026-11-08). It is the first middleware of the web group, before the
 * session starts and before the user is resolved.
 *
 * A request is a guest's ONLY when all three hold: the feature is on, the
 * guest cookie's session id exists in the demo database, and that session
 * belongs to a demo that is still live (demo_sessions). The one other guest
 * request is the one that opens a demo (/prova/oppna/{token}), and only for a
 * token whose build is ready. In every other case the request is served by the
 * main database and a guest cookie it carries is forgotten, so a leftover
 * cookie can strand no one on the demo database; with the feature off or the
 * demo database missing it never errors.
 *
 * The cookie has a name of its own: a request that has it is looked up in the
 * demo database's sessions only, and a main session id sent under the guest
 * name finds nothing there, and vice versa. The settings are put back when the
 * request has ended. The entry pages (/prova, /prova/bygger/…) and every way
 * to sign up (/logga-in, /ko, /registrera) run on the main database; the
 * latter also forget the cookie, since a person who signs up leaves the demo.
 */
class UseDemoDatabase
{
    public function handle(Request $request, Closure $next): Response
    {
        $hasCookie = $request->cookies->has(DemoMode::COOKIE);

        if ($this->isGuestRequest($request, $hasCookie)) {
            DemoMode::activateRequest();
        } elseif ($hasCookie && ! $this->isEntryPage($request)) {
            Cookie::queue(Cookie::forget(DemoMode::COOKIE));
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        DemoMode::deactivateRequest();
    }

    private function isGuestRequest(Request $request, bool $hasCookie): bool
    {
        try {
            if ($request->is('prova/oppna/*')) {
                return DemoMode::enabled() && $this->tokenIsReady((string) $request->segment(3));
            }

            if ($this->isEntryPage($request) || $this->isSignUpPath($request) || ! $hasCookie) {
                return false;
            }

            return DemoMode::enabled() && $this->cookieIsLive((string) $request->cookies->get(DemoMode::COOKIE));
        } catch (Throwable) {
            // The demo database is missing or not migrated: serve the main database, never an error.
            return false;
        }
    }

    private function isEntryPage(Request $request): bool
    {
        return $request->is('prova', 'prova/*');
    }

    private function isSignUpPath(Request $request): bool
    {
        return $request->is('logga-in', 'logga-in/*', 'ko', 'ko/*', 'registrera');
    }

    private function tokenIsReady(string $grant): bool
    {
        return DemoSessions::openExists($grant);
    }

    /** The session exists in the demo database and its guest has a demo that has not ended. */
    private function cookieIsLive(string $cookie): bool
    {
        $encrypter = app('encrypter');
        $value = $encrypter->decrypt($cookie, false);
        $id = is_string($value) ? CookieValuePrefix::validate(DemoMode::COOKIE, $value, $encrypter->getAllKeys()) : null;

        if (! is_string($id) || $id === '') {
            return false;
        }

        $demo = DB::connection(DemoMode::CONNECTION);
        $userId = $demo->table('sessions')->where('id', $id)->value('user_id');

        if ($userId === null) {
            return false;
        }

        $row = $demo->table('demo_sessions')->where('user_id', $userId)->first(['expires_at']);

        if ($row !== null && CarbonImmutable::parse((string) $row->expires_at)->isPast()) {
            // The demo this browser had has ended: tell the visitor, once, when the main session is there to flash it.
            DemoMode::markEnded();
        }

        return $row !== null && CarbonImmutable::parse((string) $row->expires_at)->isFuture();
    }
}
