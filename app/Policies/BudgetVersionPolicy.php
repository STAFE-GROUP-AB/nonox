<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\User;

/**
 * Budgets (ADR-081 gap 2): seen with budgets:view, made and changed with
 * budgets:manage. A locked version can still be copied, activated and
 * deleted, but its lines no longer change.
 */
class BudgetVersionPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'budgets:view');
    }

    public function view(User $user, BudgetVersion $version): bool
    {
        $company = $this->company($version);

        return $company instanceof Company && $user->hasTeamPermission($company, 'budgets:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'budgets:manage');
    }

    /** Activate, lock or delete the version. */
    public function manage(User $user, BudgetVersion $version): bool
    {
        $company = $this->company($version);

        return $company instanceof Company && $user->hasTeamPermission($company, 'budgets:manage');
    }

    /** Change the version's lines: only while it is a draft. */
    public function update(User $user, BudgetVersion $version): bool
    {
        return ! $version->isLocked() && $this->manage($user, $version);
    }

    private function company(BudgetVersion $version): ?Company
    {
        return Company::query()->find($version->company_id);
    }
}
