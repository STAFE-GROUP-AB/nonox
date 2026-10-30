<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;

/**
 * Suppliers: seen by those who see supplier invoices, changed by those who
 * register them.
 */
class SupplierPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return SupplierInvoicePolicy::canSeePayables($user, $company);
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'supplier-invoices:create');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        $company = Company::query()->find($supplier->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, 'supplier-invoices:create');
    }
}
