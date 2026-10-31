<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Locations and their kiosks: managers set them up, everyone with access to
 * the staff ledger sees them. Module permissions are false while the module
 * is off (User::hasTeamPermission).
 */
class LocationPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'staff-ledger:view');
    }

    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'staff-ledger:manage');
    }
}
