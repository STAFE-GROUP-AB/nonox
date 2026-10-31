<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\StaffLedger\Models\Person;

/**
 * The people in the staff ledger. Personal numbers are shown in full only to
 * those with staff-ledger:view-sensitive, and in the control visit view.
 */
class PersonPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'staff-ledger:view');
    }

    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'staff-ledger:manage');
    }

    public function viewSensitive(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'staff-ledger:view-sensitive');
    }

    /** A person checks in on their own page only as themselves. */
    public function checkInAs(User $user, Person $person): bool
    {
        $company = Company::query()->find($person->company_id);

        return $company instanceof Company
            && $person->user_id === $user->id
            && $person->archived_at === null
            && $user->hasTeamPermission($company, 'staff-ledger:self');
    }
}
