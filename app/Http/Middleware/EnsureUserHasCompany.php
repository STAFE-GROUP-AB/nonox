<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users without a company are sent to /kom-igang to create one or accept an
 * invitation (AGENTS.md §3.2).
 */
class EnsureUserHasCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->currentCompany() !== null) {
            return $next($request);
        }

        $company = $user->companies()->first();

        if ($company !== null) {
            $user->switchCompany($company);

            return $next($request);
        }

        return redirect()->route('onboarding');
    }
}
