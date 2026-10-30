<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Incoming payment files: read by those who see invoices, read in and booked
 * by those who register payments.
 */
class BankFileImportPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'payments:create');
    }
}
