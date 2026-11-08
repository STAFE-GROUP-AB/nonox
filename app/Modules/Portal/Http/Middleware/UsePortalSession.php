<?php

declare(strict_types=1);

namespace App\Modules\Portal\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives /kundportal its own session cookie (ADR-075 §2, §8): a global
 * middleware that runs before the web group's StartSession.
 *
 * What it relies on, read in the installed Laravel 13 source
 * (vendor/laravel/framework/src/Illuminate/Session):
 * - StartSession::getSession() takes the store from SessionManager::driver()
 *   and reads the id from the cookie named by the store's getName();
 *   addCookieToResponse() writes that name with session.path from
 *   SessionManager::getSessionConfig().
 * - SessionManager::buildSession() names the store from session.cookie, and
 *   the database handler takes session.lifetime when it is built; the built
 *   driver is cached (Manager::$drivers), so forgetDrivers() is needed for a
 *   new name to take effect, and the container's cached 'session.store'.
 * - SessionGuard keeps the store it was built with (AuthManager::$guards),
 *   so the guards are forgotten too.
 *
 * In production each request starts fresh; the switch back matters when one
 * process serves both (tests, Octane). The database handler writes user_id
 * from the default guard, which stays 'web': portal sessions are never
 * listed as a NONOX user's browser sessions.
 */
final class UsePortalSession
{
    public function __construct(private readonly SessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $portal = $request->is('kundportal', 'kundportal/*');
        $web = config('session.web') ?? ['cookie' => config('session.cookie'), 'path' => config('session.path'), 'lifetime' => config('session.lifetime')];

        if (config('session.web') === null) {
            config(['session.web' => $web]);
        }

        $wanted = $portal ? config('session.portal') : $web;

        if (config('session.cookie') !== $wanted['cookie'] || config('session.path') !== $wanted['path'] || (int) config('session.lifetime') !== (int) $wanted['lifetime']) {
            // A user already set on a guard before the session starts (only actingAs in tests does that) is carried over.
            $set = [];

            foreach (['web', 'portal'] as $name) {
                $guard = Auth::guard($name);

                if ($guard instanceof SessionGuard && $guard->hasUser() && $guard->user() !== null) {
                    $set[$name] = $guard->user();
                }
            }

            config(['session.cookie' => $wanted['cookie'], 'session.path' => $wanted['path'], 'session.lifetime' => $wanted['lifetime']]);
            $this->sessions->forgetDrivers();
            app()->forgetInstance('session.store');
            // The redirector keeps the store it was made with (RoutingServiceProvider::registerRedirector), for flashed errors and input.
            app()->forgetInstance('redirect');
            Facade::clearResolvedInstance('redirect');
            Auth::forgetGuards();

            foreach ($set as $name => $user) {
                Auth::guard($name)->setUser($user);
            }
        }

        return $next($request);
    }
}
