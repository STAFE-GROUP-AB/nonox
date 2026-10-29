<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;

/**
 * Cost centres and projects are part of the chart of accounts: everyone who
 * sees vouchers sees them; those who manage accounts manage them.
 */
abstract class DimensionObjectPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'vouchers:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'accounts:manage');
    }

    public function update(User $user, CostCenter|Project $object): bool
    {
        $company = Company::query()->find($object->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, 'accounts:manage');
    }
}
