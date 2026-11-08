<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Demo\DemoMode;
use App\Support\Toast;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a demo guest (ADR-077) out of the pages that are about a real
 * account: the profile with its API tokens, passkeys and account deletion,
 * and the creation of more companies.
 */
class NotForDemoGuests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DemoMode::guest()) {
            Toast::flash(DemoMode::message(), 'error');

            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
