<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\StaffAccess;

class AccountPolicy
{
    /**
     * Every member who works in the bookkeeping may see the chart of accounts.
     */
    public function viewAny(User $user, Company $company): bool
    {
        return StaffAccess::allows($user, $company);
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
