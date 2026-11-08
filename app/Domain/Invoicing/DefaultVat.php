<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Company;
use App\Models\Customer;

/**
 * The VAT rate a row gets before anyone chooses one, for a sale to this
 * customer: none when the company is not registered for VAT, when the buyer
 * accounts for the VAT (reverse charge in construction) or when the sale is
 * a VAT-free sale abroad; otherwise the standard rate. These are the same
 * conditions SaveDraftInvoice applies to the invoice and IssueInvoice
 * checks, so a quote made with this rate equals the invoice that follows.
 */
final class DefaultVat
{
    public const int STANDARD = 2500;

    public static function rateFor(Company $company, Customer $customer): int
    {
        if (! $company->isVatRegistered()) {
            return 0;
        }

        if ($customer->reverse_charge_construction || ($customer->foreign_sale !== null && $customer->foreign_sale->isVatFree())) {
            return 0;
        }

        return self::STANDARD;
    }
}
