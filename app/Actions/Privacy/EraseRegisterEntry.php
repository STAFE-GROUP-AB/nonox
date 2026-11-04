<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Domain\Invoicing\AgreementStatus;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Events\CustomerAnonymised;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\SalesDocument;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Crm\Support\SignedDocuments;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Erasure of a customer or supplier (GDPR art. 17) within Bokföringslagen:
 * an entry that never reached the books is deleted; one that did is
 * anonymised in the register, while the invoices keep their own copy of
 * the details, which is räkenskapsinformation and stays for seven years
 * (art. 17.3 b). Refused while something is still open (unpaid invoices,
 * an active agreement), and the personal number of a ROT/RUT customer is
 * kept.
 *
 * TODO(legal-verify): whether the register entry itself must be kept for
 * the retention period, and how long the personal number of a ROT/RUT
 * buyer must be kept after Skatteverket has paid.
 */
class EraseRegisterEntry
{
    /** @return 'deleted'|'anonymised' */
    public function customer(User $user, Company $company, Customer $customer): string
    {
        Gate::forUser($user)->authorize('update', $customer);

        return CurrentCompany::run($company, fn (): string => DB::transaction(function () use ($user, $company, $customer): string {
            if (Invoice::query()->where('customer_id', $customer->id)->where('status', InvoiceStatus::Issued->value)->exists()) {
                throw ValidationException::withMessages(['erase' => __('Kunden har obetalda fakturor. Uppgifterna kan tas bort när de är betalda eller krediterade.')]);
            }

            if (RecurringInvoice::query()->where('customer_id', $customer->id)->where('status', AgreementStatus::Active->value)->exists()) {
                throw ValidationException::withMessages(['erase' => __('Kunden har ett pågående avtal. Avsluta avtalet först.')]);
            }

            $used = Invoice::query()->where('customer_id', $customer->id)->exists()
                || SalesDocument::query()->where('customer_id', $customer->id)->exists()
                || RecurringInvoice::query()->where('customer_id', $customer->id)->exists()
                // A signed agreement and its evidence are kept as proof of the contract (CRM, ADR-046; art. 17.3 e).
                || SignedDocuments::customerHasSignedAgreement($customer->id);

            if (! $used) {
                $customer->delete();
                Audit::record('privacy.erased', null, ['subject' => 'customer', 'number' => $customer->number, 'mode' => 'deleted'], $company, $user);

                return 'deleted';
            }

            $keepPersonalNumber = Invoice::query()->where('customer_id', $customer->id)->whereNotNull('tax_reduction')->exists();

            $customer->forceFill([
                'name' => __('Anonymiserad kund :number', ['number' => $customer->number]),
                'org_number' => null,
                'personal_number' => $keepPersonalNumber ? $customer->personal_number : null,
                'vat_number' => null,
                'email' => null,
                'phone' => null,
                'address_line1' => null,
                'address_line2' => null,
                'postal_code' => null,
                'city' => null,
                'is_active' => false,
            ])->save();

            Audit::record('privacy.erased', $customer, ['subject' => 'customer', 'mode' => 'anonymised', 'kept_personal_number' => $keepPersonalNumber], $company, $user);
            // Modules that keep more about the customer (CRM) erase it in this transaction.
            event(new CustomerAnonymised($company->id, $customer->id));

            return 'anonymised';
        }));
    }

    /** @return 'deleted'|'anonymised' */
    public function supplier(User $user, Company $company, Supplier $supplier): string
    {
        Gate::forUser($user)->authorize('update', $supplier);

        return CurrentCompany::run($company, fn (): string => DB::transaction(function () use ($user, $company, $supplier): string {
            $open = SupplierInvoice::query()->where('supplier_id', $supplier->id)
                ->whereIn('status', [SupplierInvoiceStatus::Draft->value, SupplierInvoiceStatus::Registered->value, SupplierInvoiceStatus::Approved->value])
                ->exists();

            if ($open) {
                throw ValidationException::withMessages(['erase' => __('Leverantören har fakturor som inte är betalda. Uppgifterna kan tas bort när de är betalda.')]);
            }

            if (! SupplierInvoice::query()->where('supplier_id', $supplier->id)->exists()) {
                $supplier->delete();
                Audit::record('privacy.erased', null, ['subject' => 'supplier', 'number' => $supplier->number, 'mode' => 'deleted'], $company, $user);

                return 'deleted';
            }

            $supplier->forceFill([
                'name' => __('Anonymiserad leverantör :number', ['number' => $supplier->number]),
                'org_number' => null,
                'vat_number' => null,
                'email' => null,
                'phone' => null,
                'address_line1' => null,
                'postal_code' => null,
                'city' => null,
                'bankgiro' => null,
                'plusgiro' => null,
                'iban' => null,
                'bic' => null,
                'is_active' => false,
            ])->save();

            Audit::record('privacy.erased', $supplier, ['subject' => 'supplier', 'mode' => 'anonymised'], $company, $user);

            return 'anonymised';
        }));
    }
}
