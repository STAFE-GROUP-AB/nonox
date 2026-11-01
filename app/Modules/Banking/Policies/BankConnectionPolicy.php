<?php

declare(strict_types=1);

namespace App\Modules\Banking\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Who may see the bank feed and who may connect banks, map accounts and
 * end consents. Membership is part of hasTeamPermission; records of other
 * companies are never found (CompanyScope), so they answer 404.
 */
class BankConnectionPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'banking:view');
    }

    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'banking:manage');
    }

    /** Matching a bank transaction to a ledger row is reconciliation work, like BankReconciliations. */
    public function match(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'banking:view') && $user->hasTeamPermission($company, 'vouchers:post');
    }
}
