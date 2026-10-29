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
 * Starts a credit invoice for the whole of an unpaid invoice: a draft with
 * the same rows and negative quantities. It takes effect when issued.
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
                'rows' => $original->rows->map(fn (InvoiceRow $row): array => [
                    'description' => $row->description,
                    'quantity' => ltrim($row->quantity, '-'),
                    'unit' => $row->unit,
                    'price' => $row->unit_price_ore->format(withCurrency: false),
                    'discount' => $row->discount_bp === 0 ? '' : (string) ($row->discount_bp / 100),
                    'vat_rate' => $row->vat_rate,
                    'account' => $row->account?->number,
                ])->all(),
            ], $draft);
        });
    }
}
