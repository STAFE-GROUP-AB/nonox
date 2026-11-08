<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\RegisterImport;
use App\Models\User;

/**
 * Reading in registers, open invoices and attachments from another program
 * (ADR-076): owner, administrator and accounting consultant, through the
 * permission import:registers.
 */
class RegisterImportPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'import:registers');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'import:registers');
    }

    public function view(User $user, RegisterImport $import): bool
    {
        $company = Company::query()->find($import->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, 'import:registers');
    }

    public function confirm(User $user, RegisterImport $import): bool
    {
        return $import->canBeConfirmed() && $this->view($user, $import);
    }
}
