<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceTaxReductionBuyer;
use App\Support\Tenancy\CurrentCompany;

/**
 * The ROT or RUT buyers printed on an invoice: the customer alone, or each
 * of several buyers with the own deduction (ADR-074 O6).
 *
 * TODO(legal-verify): L6 (ADR-074 O5). Skatteverket's page "Så fungerar
 * rotavdraget" lists the buyer's personnummer among what the invoice shows,
 * so it is printed until the lawyer answers whether the claim alone is
 * enough (data minimisation). Only the PDF shows it; no screen, export or
 * audit entry does.
 */
final class TaxReductionBuyers
{
    /** @return list<array{name: string, personal_number: string, deduction: ?int}> */
    public static function forPdf(Invoice $invoice): array
    {
        // Only invoices issued from this release on (and drafts) print it: an earlier invoice is re-rendered as it was.
        if ($invoice->tax_reduction === null || (! $invoice->isDraft() && ! $invoice->prints_buyer_personal_number)) {
            return [];
        }

        /** @var list<array{name: string, personal_number: string, deduction: ?int}> */
        return CurrentCompany::run($invoice->company_id, function () use ($invoice): array {
            $buyers = $invoice->taxReductionBuyers()->with('customer')->get();

            if ($buyers->isNotEmpty()) {
                return $buyers->map(fn (InvoiceTaxReductionBuyer $buyer): array => [
                    'name' => (string) $buyer->customer?->name,
                    'personal_number' => (string) $buyer->customer?->personal_number,
                    'deduction' => $buyer->deduction_ore->ore,
                ])->filter(fn (array $buyer): bool => $buyer['personal_number'] !== '')->values()->all();
            }

            $customer = Customer::query()->find($invoice->customer_id);

            if (! $customer instanceof Customer || blank($customer->personal_number)) {
                return [];
            }

            return [['name' => $customer->name, 'personal_number' => (string) $customer->personal_number, 'deduction' => null]];
        });
    }
}
