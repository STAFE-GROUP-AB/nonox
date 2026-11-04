<?php

declare(strict_types=1);

namespace App\Support\CreditData;

/**
 * Credit information about a company from a credit reporting company
 * (ADR-047): rating, payment remarks, exact turnover and changes of CEO or
 * board. No free source has these. The owner's decision is a replaceable
 * driver once a contract exists (services.credit_data.driver); until then
 * the null driver answers that nothing is available, and the CRM says which
 * signals need it.
 *
 * A driver must only be called for legal persons (a credit report on a
 * natural person needs a legitimate need and a copy to the person under
 * kreditupplysningslagen 9 and 15 §§). TODO(legal-verify) before any
 * driver goes live.
 */
interface CreditData
{
    public function available(): bool;

    /** A short name of the provider for the UI ("Kreditupplysning från …"). */
    public function name(): string;

    /** The report for a ten-digit organisation number, or null when the provider has none. */
    public function report(string $orgNumber): ?CreditReport;
}
