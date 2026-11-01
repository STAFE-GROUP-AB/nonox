<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Search\RecentItems;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchRegistry;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the record behind a detail page (a voucher, an invoice) as
 * recently opened, for the spotlight (ADR-026). Runs after the response is
 * sent, and only for the detail routes the search providers name.
 */
final class RemembersRecentItems
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $route = $request->route();
        $user = $request->user();

        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200 || ! $user instanceof User || ! $route instanceof Route) {
            return;
        }

        $name = (string) $route->getName();

        if (! str_ends_with($name, '.show') && ! str_ends_with($name, '.edit')) {
            return;
        }

        foreach (app(SearchRegistry::class)->all() as $provider) {
            $parameter = $provider->detailRoutes()[$name] ?? null;

            if ($parameter === null) {
                continue;
            }

            $value = $route->parameter($parameter);
            $id = $value instanceof Model ? (string) $value->getKey() : (is_scalar($value) ? (string) $value : null);
            $company = $user->currentCompany();

            if ($id === null || $company === null) {
                return;
            }

            // Remembering is a convenience: it must never break the page.
            rescue(fn () => CurrentCompany::run($company, fn () => app(RecentItems::class)->remember($provider->key(), $id, new SearchContext($user, $company))), report: false);

            return;
        }
    }
}
