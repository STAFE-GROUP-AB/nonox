<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScope;
use App\Support\Tenancy\DimensionScopeRegistry;
use App\Support\Tenancy\ScopeDecision;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a member limited to cost centres or projects (ADR-081 gap 10) on the
 * pages DimensionScopeRegistry opens for them: every other route answers 404,
 * as another company's record does (AGENTS.md §13.2). On the web and on the
 * API (after AuthenticateConnect, so an API key or OAuth token of a limited
 * member carries the scope). Costs nothing for anyone else: whether a member
 * is limited comes with the membership already read.
 */
class EnforceDimensionScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companyId = $user instanceof User ? CurrentCompany::id() : null;

        if (! $user instanceof User || $companyId === null || ! DimensionScope::limits($user, $companyId)) {
            return $next($request);
        }

        $route = $request->route();

        // Public pages and Livewire's own endpoint (LivewireDimensionScopeGuard checks each component).
        if (! $route instanceof Route || ! DimensionScopeRegistry::requiresMember($route)) {
            return $next($request);
        }

        $decision = DimensionScopeRegistry::route(DimensionScopeRegistry::key($route));

        if ($decision === ScopeDecision::Landing) {
            $company = $user->currentCompany();

            return $company instanceof Company
                ? redirect()->to(DimensionScopeRegistry::landing($user, $company))
                : $next($request);
        }

        abort_unless(in_array($decision, [ScopeDecision::Scoped, ScopeDecision::Neutral], true), 404);

        return $next($request);
    }
}
