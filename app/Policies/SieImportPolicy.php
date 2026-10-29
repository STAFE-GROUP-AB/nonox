<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\SieImport;
use App\Models\User;

class SieImportPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'import:sie');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'import:sie');
    }

    public function view(User $user, SieImport $import): bool
    {
        $company = Company::query()->find($import->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, 'import:sie');
    }

    public function confirm(User $user, SieImport $import): bool
    {
        return $import->canBeConfirmed() && $this->view($user, $import);
    }
}
