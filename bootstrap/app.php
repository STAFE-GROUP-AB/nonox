<?php

declare(strict_types=1);

use App\Http\Middleware\EndExpiredSessions;
use App\Http\Middleware\RequirePasskey;
use App\Http\Middleware\RemembersRecentItems;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Http\Middleware\EnsureModuleEnabled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'module' => EnsureModuleEnabled::class,
        ]);

        // Trusted proxies are opt-in through TRUSTED_PROXIES, read on every request
        // from config/trustedproxy.php. Not ->trustProxies(at: ...) here: this
        // callback runs before .env is loaded, so env() would always be empty.

        $middleware->web(append: [
            SecurityHeaders::class,
            EndExpiredSessions::class,
            // After EndExpiredSessions: a session that has ended needs no passkey step (ADR-029).
            RequirePasskey::class,
            RemembersRecentItems::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
