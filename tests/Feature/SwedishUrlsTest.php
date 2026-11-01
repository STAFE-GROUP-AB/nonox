<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * AGENTS.md §11.4: every user-facing URL is Swedish, lowercase ASCII with
 * hyphens. Technical endpoints users never see are exempt.
 */

// oauth/ and .well-known/ are called by apps, with paths fixed by RFC 6749 and RFC 8414 (ADR-032).
// utvecklare/openapi.yaml and .json keep the names API tools and code generators look for.
// manual/sokindex.json is the search index the manual's search box fetches (ADR-036).
const TECHNICAL_PREFIXES = ['up', 'sitemap.xml', 'robots.txt', 'livewire', 'api/', 'sanctum/', 'storage/', '_', 'herd-studio', 'oauth/', '.well-known/', 'utvecklare/openapi.', 'manual/sokindex.json'];

const ENGLISH_WORDS = [
    'login', 'logout', 'register', 'user', 'profile', 'team', 'teams', 'dashboard', 'password',
    'invitation', 'invitations', 'company', 'companies', 'create', 'settings', 'onboarding',
    'current', 'forgot', 'reset', 'verify', 'two-factor', 'confirm', 'accept',
];

/** @return list<RoutingRoute> */
function userFacingRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        function (RoutingRoute $route): bool {
            $uri = $route->uri();

            foreach (TECHNICAL_PREFIXES as $prefix) {
                if (str_starts_with($uri, $prefix)) {
                    return false;
                }
            }

            return true;
        },
    ));
}

it('has user-facing routes to check', function () {
    expect(count(userFacingRoutes()))->toBeGreaterThan(5);
});

it('uses lowercase ASCII paths with hyphens', function () {
    foreach (userFacingRoutes() as $route) {
        $path = preg_replace('/\{[^}]+\}/', '', $route->uri());

        expect($path)->toMatch('#^[a-z0-9/-]*$#', "'{$route->uri()}' is not lowercase ASCII.");
    }
});

it('uses Swedish words in every user-facing path', function () {
    foreach (userFacingRoutes() as $route) {
        $segments = preg_split('#[/-]#', preg_replace('/\{[^}]+\}/', '', $route->uri()) ?? '') ?: [];

        foreach ($segments as $segment) {
            expect(in_array($segment, ENGLISH_WORDS, true))
                ->toBeFalse("'{$route->uri()}' contains the English word '{$segment}'.");
        }
    }
});
