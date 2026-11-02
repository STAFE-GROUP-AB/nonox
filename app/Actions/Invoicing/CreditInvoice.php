<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Starts a credit invoice for an issued invoice, unpaid or paid: a draft
 * with the same rows and negative quantities, which can be changed to
 * credit only part of it. It takes effect when issued (IssueInvoice).
 */
class CreditInvoice
{
    public function __construct(private readonly SaveDraftInvoice $saveDraft) {}

    public function __invoke(User $user, Invoice $original): Invoice
    {
        Gate::forUser($user)->authorize('credit', $original);

        return CurrentCompany::run($original->company_id, function () use ($user, $original): Invoice {
            $original->load('rows.account');
            $company = Company::query()->findOrFail($original->company_id);

            $draft = new Invoice(['created_by' => $user->id, 'kind' => Invoice::CREDIT, 'credits_invoice_id' => $original->id, 'customer_id' => $original->customer_id]);
            $draft->forceFill([
                'invoice_date' => CarbonImmutable::today(),
                'due_date' => CarbonImmutable::today(),
            ])->save();

            return ($this->saveDraft)($user, $company, [
                'customer_id' => $original->customer_id,
                'invoice_date' => CarbonImmutable::today()->toDateString(),
                'due_date' => CarbonImmutable::today()->toDateString(),
                'your_reference' => $original->your_reference,
                'our_reference' => $original->our_reference,
                'notes' => __('Kreditering av faktura :number.', ['number' => $original->number]),
                'reverse_charge' => $original->reverse_charge,
                'foreign_sale' => $original->foreign_sale->value ?? '',
                'tax_reduction' => $original->tax_reduction,
                'property_designation' => $original->property_designation,
                'rows' => $original->rows->map(fn (InvoiceRow $row): array => $row->asInput())->all(),
            ], $draft);
        });
    }
}
