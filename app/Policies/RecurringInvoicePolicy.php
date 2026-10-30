<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\RecurringInvoice;
use App\Models\User;

/**
 * Invoicing agreements: seen by those who see invoices, written by those who
 * write them.
 */
class RecurringInvoicePolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:view');
    }

    public function view(User $user, RecurringInvoice $agreement): bool
    {
        return $this->can($user, $agreement, 'invoices:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:create');
    }

    public function update(User $user, RecurringInvoice $agreement): bool
    {
        return $this->can($user, $agreement, 'invoices:create');
    }

    private function can(User $user, RecurringInvoice $agreement, string $permission): bool
    {
        $company = Company::query()->find($agreement->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, $permission);
    }
}
