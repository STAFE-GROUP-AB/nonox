<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\PasskeySession;
use App\Support\Hq\HqAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /hq (ADR-038) answers 404 to everyone but an HQ admin, guests included:
 * not a redirect to the sign-in page, which would tell that the area
 * exists. Used instead of the auth middleware for that reason, so it also
 * sends an admin whose session still needs its passkey step there first.
 */
class EnsureHqAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = HqAccess::ensure($request->user());

        if (PasskeySession::needsStep($user)) {
            session()->put('url.intended', $request->fullUrl());

            return redirect()->route('passkeys.challenge');
        }

        return $next($request);
    }
}
