<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Article;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;

/**
 * Customers and articles: seen by those who see invoices, changed by those
 * who write them.
 */
abstract class SalesRegisterPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:create');
    }

    public function update(User $user, Customer|Article $record): bool
    {
        $company = Company::query()->find($record->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, 'invoices:create');
    }
}
