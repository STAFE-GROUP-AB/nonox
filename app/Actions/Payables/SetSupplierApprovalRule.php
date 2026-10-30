<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Gate;

/**
 * Turns the requirement on or off that someone other than the person who
 * registered a supplier invoice approves it (AGENTS.md §4, default on). A
 * company with one person must turn it off to pay its invoices.
 */
class SetSupplierApprovalRule
{
    public function __invoke(User $user, Company $company, bool $separate): void
    {
        Gate::forUser($user)->authorize('update', $company);

        $company->forceFill(['separate_supplier_approval' => $separate])->save();

        Audit::record('company.supplier_approval_changed', $company, ['separate_supplier_approval' => $separate], $company, $user);
    }
}
