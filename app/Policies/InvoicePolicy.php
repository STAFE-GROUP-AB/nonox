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
        return ! $invoice->isDraft() && ! $invoice->isImported() && $this->can($user, $invoice, 'invoices:send');
    }

    /**
     * An unpaid, part-paid or paid invoice can be credited while some of it
     * is not credited yet (Mervärdesskattelag (2023:200) 17 kap. 22-23 §§,
     * 7 kap. 46 §).
     */
    public function credit(User $user, Invoice $invoice): bool
    {
        return ! $invoice->isCredit()
            && in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Paid], true)
            && $invoice->creditable()['kronor'] > 0
            && $this->can($user, $invoice, 'invoices:credit');
    }

    /** A credit invoice that left the customer a credit balance can be paid back. */
    public function registerRefund(User $user, Invoice $invoice): bool
    {
        return $invoice->isCredit()
            && $invoice->status === InvoiceStatus::Issued
            && $invoice->remaining()->isNegative()
            && $this->can($user, $invoice, 'payments:create');
    }

    public function remind(User $user, Invoice $invoice): bool
    {
        return ! $invoice->isCredit() && $invoice->isOverdue() && $this->can($user, $invoice, 'invoices:send');
    }

    public function registerPayment(User $user, Invoice $invoice): bool
    {
        return ! $invoice->isCredit()
            && ($invoice->status === InvoiceStatus::Issued || ($invoice->status === InvoiceStatus::WrittenOff && $invoice->recovered_ore->ore < $invoice->written_off_ore->ore))
            && $this->can($user, $invoice, 'payments:create');
    }

    /**
     * The open part of an invoice can be written off as a confirmed customer
     * loss (#57), by whoever may credit it. A ROT or RUT invoice cannot yet.
     */
    public function writeOff(User $user, Invoice $invoice): bool
    {
        return ! $invoice->isCredit()
            && $invoice->status === InvoiceStatus::Issued
            && $invoice->remaining()->ore > 0
            && $invoice->tax_reduction === null
            && $this->can($user, $invoice, 'invoices:credit');
    }

    /**
     * Skatteverket's ROT or RUT payment can come before or after the customer
     * has paid, for what is still claimed after any credits.
     */
    public function registerTaxReductionPayment(User $user, Invoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Paid], true)
            && $invoice->tax_reduction !== null
            && ! $invoice->isCredit()
            && $invoice->taxReductionClaimable()->ore > 0
            && $this->can($user, $invoice, 'payments:create');
    }

    /** Paying back to Skatteverket a deduction a credit invoice took back from the buyer. */
    public function registerTaxReductionRepayment(User $user, Invoice $invoice): bool
    {
        return $invoice->isCredit()
            && ! $invoice->isDraft()
            && $invoice->taxReductionRepaymentLeft()->ore > 0
            && $this->can($user, $invoice, 'payments:create');
    }

    private function can(User $user, Invoice $invoice, string $permission): bool
    {
        $company = Company::query()->find($invoice->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, $permission);
    }
}
