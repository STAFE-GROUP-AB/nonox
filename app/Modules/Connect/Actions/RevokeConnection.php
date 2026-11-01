<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Models\ConnectGrant;
use App\Modules\Connect\OAuth\GrantTokens;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Disconnects an app from the company: the grant and every token under it
 * stop working at once (ADR-032). Owners and administrators can revoke any
 * app; the person who connected it can always revoke their own.
 */
final class RevokeConnection
{
    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $user, Company $company, ConnectGrant $grant): void
    {
        if ($grant->company_id !== $company->id) {
            throw new AuthorizationException;
        }

        if (! $user->hasTeamPermission($company, 'connect:manage') && $grant->user_id !== $user->id) {
            throw new AuthorizationException;
        }

        DB::transaction(fn () => GrantTokens::revoke($grant->id, GrantTokens::BY_USER, $user));
    }
}
