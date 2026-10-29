<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;

/**
 * An issued invoice is never edited or deleted; it is credited.
 */
class InvoicePolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->can($user, $invoice, 'invoices:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:create');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $invoice->isDraft() && $this->can($user, $invoice, 'invoices:create');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $invoice->isDraft() && $this->can($user, $invoice, 'invoices:send');
    }

    public function send(User $user, Invoice $invoice): bool
    {
        return ! $invoice->isDraft() && $this->can($user, $invoice, 'invoices:send');
    }

    public function credit(User $user, Invoice $invoice): bool
    {
        return ! $invoice->isCredit()
            && $invoice->status === InvoiceStatus::Issued
            && $invoice->paid_ore->isZero()
            && $this->can($user, $invoice, 'invoices:credit');
    }

    public function remind(User $user, Invoice $invoice): bool
    {
        return ! $invoice->isCredit() && $invoice->isOverdue() && $this->can($user, $invoice, 'invoices:send');
    }

    public function registerPayment(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Issued && $this->can($user, $invoice, 'payments:create');
    }

    private function can(User $user, Invoice $invoice, string $permission): bool
    {
        $company = Company::query()->find($invoice->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, $permission);
    }
}
