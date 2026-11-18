<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;

/**
 * Projects as a dimension (ADR-009) and as a register (ADR-081 gap 6):
 * `projects:view` sees the register, `projects:manage` keeps it; those who
 * see vouchers or manage accounts keep doing so as before.
 */
class ProjectPolicy extends DimensionObjectPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return parent::viewAny($user, $company) || $user->hasTeamPermission($company, 'projects:view');
    }

    public function create(User $user, Company $company): bool
    {
        return parent::create($user, $company) || $user->hasTeamPermission($company, 'projects:manage');
    }

    public function update(User $user, CostCenter|Project $object): bool
    {
        if (parent::update($user, $object)) {
            return true;
        }

        $company = Company::query()->find($object->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, 'projects:manage');
    }
}
