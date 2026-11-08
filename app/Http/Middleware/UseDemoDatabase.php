<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Demo\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switches a guest's whole request to the demo database (ADR-077, addendum
 * 2026-11-08). It is the first middleware of the web group, before the
 * session starts and before the user is resolved.
 *
 * A request is a guest's when it carries the guest session cookie
 * (DemoMode::COOKIE), or when it is the one that opens a demo
 * (/prova/oppna/…). The public pages of the demo entry (/prova, /prova/bygger/…)
 * are never a guest's: they run on the main database like any public page.
 *
 * The guest cookie has a name of its own. A request that has it is looked up
 * in the demo database's sessions only, so a main session id sent under that
 * name finds nothing; a request without it is looked up in the main
 * database's sessions only, so a guest's id sent under the main name finds
 * nothing. The settings are put back when the request has ended.
 */
class UseDemoDatabase
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isGuestRequest($request)) {
            DemoMode::activateRequest();
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        DemoMode::deactivateRequest();
    }

    private function isGuestRequest(Request $request): bool
    {
        if ($request->is('prova/oppna/*')) {
            return true;
        }

        if ($request->is('prova', 'prova/*')) {
            return false;
        }

        return $request->cookies->has(DemoMode::COOKIE);
    }
}
