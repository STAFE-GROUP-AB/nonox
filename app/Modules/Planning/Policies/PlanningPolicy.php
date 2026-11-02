<?php

declare(strict_types=1);

namespace App\Modules\Planning\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Shifts, templates, areas and skills: planners plan, everyone with
 * planning:view sees the schedule. hasTeamPermission checks the membership
 * first, and a module permission is false while the module is off.
 */
class PlanningPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'planning:view');
    }

    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'planning:manage');
    }

    public function attest(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'planning:attest');
    }
}
