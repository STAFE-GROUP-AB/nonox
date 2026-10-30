<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\SupplierPaymentBatch;
use App\Models\User;

/**
 * Payment files: made, confirmed and cancelled by those who register
 * payments; read by those who see supplier invoices.
 */
class SupplierPaymentBatchPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return SupplierInvoicePolicy::canSeePayables($user, $company);
    }

    public function view(User $user, SupplierPaymentBatch $batch): bool
    {
        $company = Company::query()->find($batch->company_id);

        return $company instanceof Company && SupplierInvoicePolicy::canSeePayables($user, $company);
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'payments:create');
    }

    public function settle(User $user, SupplierPaymentBatch $batch): bool
    {
        $company = Company::query()->find($batch->company_id);

        return $batch->isOpen() && $company instanceof Company && $user->hasTeamPermission($company, 'payments:create');
    }
}
