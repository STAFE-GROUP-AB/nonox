<?php

declare(strict_types=1);

namespace App\Modules\Crm\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;

/**
 * Every CRM record (ADR-043): hasTeamPermission checks the membership first,
 * and a module permission is false while the module is off. Only crm:manage
 * writes; the key figures are part of crm:view, since a seller needs to know
 * whether a customer pays.
 */
class CrmPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'crm:view');
    }

    public function view(User $user, Account|Person|Activity $record): bool
    {
        $company = Company::query()->find($record->company_id);

        return $company instanceof Company && $this->viewAny($user, $company);
    }

    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'crm:manage');
    }

    public function update(User $user, Account|Person|Activity $record): bool
    {
        $company = Company::query()->find($record->company_id);

        return $company instanceof Company && $this->manage($user, $company);
    }

    public function settings(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'crm:settings');
    }
}
