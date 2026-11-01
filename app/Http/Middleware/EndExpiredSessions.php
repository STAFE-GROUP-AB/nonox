<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a signed-in session that is older than the absolute limit, or that
 * started before the user chose "Logga ut överallt" (users.sessions_valid_from),
 * or whose account has been deleted (users.anonymised_at).
 * The idle timeout is the session lifetime; this adds an upper bound, so a
 * stolen session cannot be kept alive forever.
 */
class EndExpiredSessions
{
    public const string STARTED = 'auth.started_at';

    public const int ABSOLUTE_HOURS = 12;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $started = $request->session()->get(self::STARTED);

        if (! is_int($started)) {
            $request->session()->put(self::STARTED, $started = time());
        }

        $revoked = $user->sessions_valid_from !== null && $started < $user->sessions_valid_from->getTimestamp();

        if ($revoked || $user->anonymised_at !== null || $started < time() - self::ABSOLUTE_HOURS * 3600) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
