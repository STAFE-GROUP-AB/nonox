<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Auth\Access\Response;

/**
 * A registered supplier invoice is never edited or deleted. It is approved
 * (attest) by someone other than the person who registered it, unless the
 * company has turned that off, and only an approved invoice is paid
 * (AGENTS.md §4).
 *
 * A member limited to cost centres or projects (ADR-081 gap 10) reaches an
 * invoice only when its rows are inside the scope; any other answers 404.
 */
class SupplierInvoicePolicy
{
    public const array VIEW_PERMISSIONS = [
        'supplier-invoices:create', 'supplier-invoices:approve', 'payments:create', 'payments:approve', 'vouchers:view',
    ];

    public static function canSeePayables(User $user, Company $company): bool
    {
        foreach (self::VIEW_PERMISSIONS as $permission) {
            if ($user->hasTeamPermission($company, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function viewAny(User $user, Company $company): bool
    {
        return self::canSeePayables($user, $company);
    }

    public function view(User $user, SupplierInvoice $invoice): bool|Response
    {
        $company = $this->company($invoice);

        if (! $company instanceof Company) {
            return false;
        }

        if (! self::canSeePayables($user, $company)) {
            return DimensionScope::limits($user, $company) ? Response::denyAsNotFound() : false;
        }

        return $this->inScope($user, $company, $invoice);
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'supplier-invoices:create');
    }

    public function update(User $user, SupplierInvoice $invoice): bool|Response
    {
        if (! $invoice->isDraft()) {
            return false;
        }

        return $this->can($user, $invoice, 'supplier-invoices:create');
    }

    public function delete(User $user, SupplierInvoice $invoice): bool|Response
    {
        return $this->update($user, $invoice);
    }

    public function register(User $user, SupplierInvoice $invoice): bool|Response
    {
        return $this->update($user, $invoice);
    }

    public function approve(User $user, SupplierInvoice $invoice): bool|Response
    {
        if ($invoice->status !== SupplierInvoiceStatus::Registered || $this->blockedBySegregation($user, $invoice)) {
            return false;
        }

        return $this->can($user, $invoice, 'supplier-invoices:approve');
    }

    /**
     * Whether the user holds the approval permission at all, whatever the
     * invoice's state.
     */
    public function approveAny(User $user, SupplierInvoice $invoice): bool|Response
    {
        return $this->can($user, $invoice, 'supplier-invoices:approve');
    }

    /**
     * True when the user could approve, were it not for having registered the
     * invoice themselves.
     */
    public function blockedBySegregation(User $user, SupplierInvoice $invoice): bool
    {
        $company = $this->company($invoice);

        return $company instanceof Company
            && $company->separate_supplier_approval
            && in_array($user->id, [$invoice->registered_by, $invoice->created_by], true);
    }

    public function pay(User $user, SupplierInvoice $invoice): bool|Response
    {
        if ($invoice->status !== SupplierInvoiceStatus::Approved) {
            return false;
        }

        return $this->can($user, $invoice, 'payments:create');
    }

    private function can(User $user, SupplierInvoice $invoice, string $permission): bool|Response
    {
        $company = $this->company($invoice);

        if (! $company instanceof Company) {
            return false;
        }

        if (! $user->hasTeamPermission($company, $permission)) {
            return DimensionScope::limits($user, $company) ? Response::denyAsNotFound() : false;
        }

        return $this->inScope($user, $company, $invoice);
    }

    private function inScope(User $user, Company $company, SupplierInvoice $invoice): bool|Response
    {
        return DimensionScope::for($user, $company)->allowsRecord($invoice) ? true : Response::denyAsNotFound();
    }

    private function company(SupplierInvoice $invoice): ?Company
    {
        return Company::query()->find($invoice->company_id);
    }
}
