<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Middleware;

use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The scope a route needs, "a|b" for either (ADR-032). Passes only when the
 * credential has the scope and the person behind it has the permission in
 * the company right now (User::hasTeamPermission checks both). Actions and
 * Policies check again on their own; this keeps endpoints that only need
 * membership, like the chart of accounts, behind a scope too.
 */
final class RequireScope
{
    public function handle(Request $request, Closure $next, string $scopes): Response
    {
        $credential = ConnectAccess::current();

        if ($credential === null) {
            return Problem::unauthenticated();
        }

        foreach (explode('|', $scopes) as $scope) {
            if ($credential->user->hasTeamPermission($credential->company, $scope)) {
                return $next($request);
            }
        }

        return Problem::make(403, 'insufficient_scope', __('Nyckeln eller appen saknar behörigheten :scope, eller så har personen som gav den inte längre den behörigheten i företaget.', ['scope' => str_replace('|', __(' eller '), $scopes)]), [
            'required_scopes' => explode('|', $scopes),
        ], [
            'WWW-Authenticate' => 'Bearer realm="nonox", error="insufficient_scope", scope="'.str_replace('|', ' ', $scopes).'"',
        ]);
    }
}
