<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Middleware;

use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The scope a route needs (ADR-032): "a|b" for either, "a+b" for both. A
 * route that books a voucher as part of its work (issuing an invoice,
 * registering a payment) names vouchers:create+vouchers:post too, since the
 * Action posts the voucher as the person behind the credential. Passes only when the
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

        $alternatives = explode('|', $scopes);

        foreach ($alternatives as $alternative) {
            $all = explode('+', $alternative);

            if (array_filter($all, fn (string $scope): bool => ! $credential->user->hasTeamPermission($credential->company, $scope)) === []) {
                return $next($request);
            }
        }

        $readable = implode(__(' eller '), array_map(fn (string $alternative): string => str_replace('+', __(' och '), $alternative), $alternatives));

        return Problem::make(403, 'insufficient_scope', __('Nyckeln eller appen saknar behörigheten :scope, eller så har personen som gav den inte längre den behörigheten i företaget.', ['scope' => $readable]), [
            // Every scope named, and the sets as in x-required-scopes: any one set, with all of its scopes.
            'required_scopes' => array_values(array_unique(explode(' ', str_replace(['|', '+'], ' ', $scopes)))),
            'required_scope_sets' => array_map(fn (string $alternative): array => explode('+', $alternative), $alternatives),
        ], [
            'WWW-Authenticate' => 'Bearer realm="nonox", error="insufficient_scope", scope="'.str_replace(['|', '+'], ' ', $scopes).'"',
        ]);
    }
}
