<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Company\VatExemption;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;

/**
 * When an invoice must be a full invoice that carries the buyer's full name
 * and address (Mervärdesskattelag (2023:200) 17 kap. 24 § p. 6), read
 * 2026-11-02 at https://data.riksdagen.se/dokument/sfs-2023-200.text
 * (enligt granskning 2026-11-02, juristgranskning återstår (AUD-FAKT-7, #55)):
 *
 * - The invoicing duty covers sales to businesses and legal persons
 *   (17 kap. 10 §) and construction services to private persons (11 § p. 3).
 *   A buyer counts as a business here when it has an organisation number or
 *   a VAT number, or the invoice is under reverse charge or to a business
 *   in another EU country; a ROT invoice is treated as construction.
 * - A simplified invoice is allowed up to 4 000 kr including VAT (26 § p. 1),
 *   for a credit note (26 § p. 3) and for a company under the small-business
 *   exemption in 18 kap. (26 § p. 4), except for an intra-Community supply
 *   of goods and for supplies in another EU country where the buyer is liable
 *   for the VAT (27 §).
 *
 * TODO(legal-verify): treating a customer without organisation, VAT or
 * personal number as a private person, and every ROT job as a construction
 * service.
 */
final class FullInvoice
{
    /** ML 17 kap. 26 § p. 1: the most a simplified invoice may total, VAT included. */
    public const int SIMPLIFIED_LIMIT_ORE = 400_000;

    public static function requiresBuyerAddress(Company $company, Invoice $invoice, Customer $customer): bool
    {
        if ($invoice->isCredit()) {
            return false;
        }

        $sale = $invoice->bookedForeignSale();
        $business = filled($customer->org_number) || filled($customer->vat_number) || $invoice->reverse_charge || $sale?->requiresBuyerVatNumber() === true;
        $construction = $invoice->tax_reduction === TaxReduction::ROT;

        if (! $business && ! $construction) {
            return false;
        }

        // 27 §: an EU supply of goods (10 kap. 42 §), or one where the buyer in another EU country accounts for the VAT (7 §).
        if (in_array($sale, [ForeignSale::EuGoods, ForeignSale::EuServices, ForeignSale::EuTriangular], true)) {
            return true;
        }

        if (! $company->isVatRegistered() && $company->vat_exemption === VatExemption::SmallBusiness) {
            return false;
        }

        return $invoice->total_ore->ore + $invoice->tax_reduction_ore->ore > self::SIMPLIFIED_LIMIT_ORE;
    }

    public static function hasFullAddress(Customer $customer): bool
    {
        return filled($customer->address_line1) && filled($customer->postal_code) && filled($customer->city);
    }
}
