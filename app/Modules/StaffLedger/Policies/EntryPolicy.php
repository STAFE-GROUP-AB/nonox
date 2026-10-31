<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * The ledger: reading it, registering manually, correcting, and opening the
 * control visit view (which shows personal numbers in full).
 */
class EntryPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'staff-ledger:view');
    }

    public function record(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'staff-ledger:manage');
    }

    public function correct(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'staff-ledger:manage');
    }

    public function controlVisit(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'staff-ledger:control-visit');
    }
}
