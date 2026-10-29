<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;

class AccountPolicy
{
    /**
     * Every member may see the chart of accounts.
     */
    public function viewAny(User $user, Company $company): bool
    {
        return $user->belongsToTeam($company);
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'accounts:manage');
    }

    public function update(User $user, Account $account): bool
    {
        $company = $account->company;

        return $company instanceof Company && $user->hasTeamPermission($company, 'accounts:manage');
    }
}
