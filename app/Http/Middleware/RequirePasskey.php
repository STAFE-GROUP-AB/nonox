<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Livewire\Auth\PasskeyChallenge;
use App\Models\User;
use App\Support\Auth\PasskeySession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in session that must pass the passkey step (ADR-029) reaches
 * nothing but the passkey page and sign-out until it has. The sign-in form
 * already asks for the passkey before the session starts; this catches a
 * session that became subject to the rule afterwards, such as a member given
 * the payroll administrator role, or one that began before passkeys existed.
 */
class RequirePasskey
{
    private const array ALLOWED_ROUTES = ['passkeys.challenge', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        // The same test as Livewire::isLivewireRequest(), on this request rather than the container's.
        $livewire = $request->hasHeader('X-Livewire');

        // Public pages (start page, legal texts) stay open; every page that needs a signed-in user does not.
        if (! $livewire && ! $this->needsSignIn($request)) {
            return $next($request);
        }

        if (! PasskeySession::needsStep($user)) {
            return $next($request);
        }

        if ($livewire) {
            if ($this->onlyThePasskeyPage($request)) {
                return $next($request);
            }

            abort(403, __('Logga in med din passkey först.'));
        }

        if (! $request->isMethod('GET') || $request->expectsJson()) {
            abort(403, __('Logga in med din passkey först.'));
        }

        session()->put('url.intended', $request->fullUrl());

        return redirect()->route('passkeys.challenge');
    }

    private function needsSignIn(Request $request): bool
    {
        foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:'))) {
                return true;
            }
        }

        return false;
    }

    /** A Livewire update for the passkey page's own component only. */
    private function onlyThePasskeyPage(Request $request): bool
    {
        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        $name = app('livewire.factory')->resolveComponentName(PasskeyChallenge::class);

        foreach ($components as $component) {
            $snapshot = is_array($component) && is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;

            if (! is_array($snapshot) || ($snapshot['memo']['name'] ?? null) !== $name) {
                return false;
            }
        }

        return true;
    }
}
