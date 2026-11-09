<?php

declare(strict_types=1);

use App\Http\Middleware\EndExpiredSessions;
use App\Http\Middleware\NotForDemoGuests;
use App\Http\Middleware\RemembersRecentItems;
use App\Http\Middleware\RequirePasskey;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UseDemoDatabase;
use App\Modules\Connect\Support\Problem;
use App\Modules\Http\Middleware\EnsureModuleEnabled;
use App\Support\Chat\SecureChat;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            'not-demo-guest' => NotForDemoGuests::class,
        ]);

        // Trusted proxies are opt-in through TRUSTED_PROXIES, read on every request
        // from config/trustedproxy.php. Not ->trustProxies(at: ...) here: this
        // callback runs before .env is loaded, so env() would always be empty.

        // The start page's migrate button works before the sessions table exists; it is guarded by a signed URL instead.
        $middleware->validateCsrfTokens(except: ['start/uppdatera-databasen']);

        $middleware->web(prepend: [
            // First: a guest's request switches to the demo database before the session starts (ADR-077).
            UseDemoDatabase::class,
        ], append: [
            SecurityHeaders::class,
            EndExpiredSessions::class,
            // After EndExpiredSessions: a session that has ended needs no passkey step (ADR-029).
            RequirePasskey::class,
            RemembersRecentItems::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The encrypted chat leaves no trace (ADR-045): its fields are never flashed to the session, and an
        // error in it is logged by class only, never with its message, trace or request, which could name a chat.
        $exceptions->dontFlash(['room', 'iv', 'ct', 'path']);
        $exceptions->report(function (Throwable $exception) {
            if (! app()->bound('request') || ! SecureChat::handles(request())) {
                return null;
            }

            Log::error('Encrypted chat request failed.', ['exception' => $exception::class]);

            return false;
        });

        // The public API answers every error as RFC 9457 problem+json (ADR-032).
        $exceptions->render(fn (Throwable $exception, Request $request) => Problem::fromException($exception, $request));
    })->create();
