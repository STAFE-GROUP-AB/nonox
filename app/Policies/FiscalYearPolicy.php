<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;

class FiscalYearPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->belongsToTeam($company);
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'fiscal-years:manage');
    }

    public function update(User $user, FiscalYear $fiscalYear): bool
    {
        $company = $fiscalYear->company;

        return $company instanceof Company && $user->hasTeamPermission($company, 'fiscal-years:manage');
    }

    public function exportSie(User $user, FiscalYear $fiscalYear): bool
    {
        $company = $fiscalYear->company;

        return $company instanceof Company && $user->hasTeamPermission($company, 'export:sie');
    }
}
