<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\SupplierPaymentBatch;
use App\Models\User;

/**
 * Payment files: made, confirmed and cancelled by those who register
 * payments; approved by those who approve payments, but not by the person
 * who made the file while the company requires separate approval
 * (AGENTS.md §4); read by those who see supplier invoices.
 */
class SupplierPaymentBatchPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return SupplierInvoicePolicy::canSeePayables($user, $company);
    }

    public function view(User $user, SupplierPaymentBatch $batch): bool
    {
        $company = $this->company($batch);

        return $company instanceof Company && SupplierInvoicePolicy::canSeePayables($user, $company);
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'payments:create');
    }

    public function approve(User $user, SupplierPaymentBatch $batch): bool
    {
        return $batch->isOpen()
            && ! $batch->isApproved()
            && $this->approveAny($user, $batch)
            && ! $this->blockedBySegregation($user, $batch);
    }

    /**
     * Whether the user holds the payment approval permission at all, whatever
     * the batch's state.
     */
    public function approveAny(User $user, SupplierPaymentBatch $batch): bool
    {
        $company = $this->company($batch);

        return $company instanceof Company && $user->hasTeamPermission($company, 'payments:approve');
    }

    /**
     * True when the user made the file and the company requires someone else
     * to approve it. The same setting governs the attest of supplier invoices.
     */
    public function blockedBySegregation(User $user, SupplierPaymentBatch $batch): bool
    {
        $company = $this->company($batch);

        return $company instanceof Company
            && $company->separate_supplier_approval
            && $user->id === $batch->created_by;
    }

    public function settle(User $user, SupplierPaymentBatch $batch): bool
    {
        $company = $this->company($batch);

        return $batch->isOpen() && $company instanceof Company && $user->hasTeamPermission($company, 'payments:create');
    }

    private function company(SupplierPaymentBatch $batch): ?Company
    {
        return Company::query()->find($batch->company_id);
    }
}
