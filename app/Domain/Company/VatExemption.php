<?php

declare(strict_types=1);

namespace App\Domain\Company;

/**
 * Why a company is not registered for VAT, and what its invoices must say
 * about it (enligt granskning 2026-11-02, juristgranskning återstår
 * (AUD-FAKT-5, #51)). Mervärdesskattelag (2023:200), read 2026-11-02 at
 * https://data.riksdagen.se/dokument/sfs-2023-200.text:
 *
 * - SmallBusiness: supplies are exempt under 18 kap. 4 § (annual turnover
 *   at most 120 000 kr). An invoice must state that the supply is exempt
 *   under that provision (17 kap. 28 § p. 6), and the company may always
 *   issue simplified invoices (17 kap. 26 § p. 4).
 * - ExemptSupplies: the company only makes supplies exempt under 10 kap.;
 *   the invoice states the exemption (17 kap. 24 § p. 13 c).
 * - RegistrationPending: the company is liable to VAT but not registered
 *   yet. It owes the VAT anyway, so its invoices carry VAT and no
 *   exemption wording.
 *
 * Whoever charges VAT wrongly on an invoice must pay it to the state
 * (16 kap. 23 §).
 *
 * TODO(legal-verify): the wording on the invoice, and how VAT charged by a
 * company that is not registered is reported and paid.
 */
enum VatExemption: string
{
    case SmallBusiness = 'SMALL_BUSINESS';
    case ExemptSupplies = 'EXEMPT_SUPPLIES';
    case RegistrationPending = 'REGISTRATION_PENDING';

    public function label(): string
    {
        return match ($this) {
            self::SmallBusiness => __('Liten omsättning, högst 120 000 kr per år (18 kap. mervärdesskattelagen)'),
            self::ExemptSupplies => __('Bara momsfri försäljning (10 kap. mervärdesskattelagen)'),
            self::RegistrationPending => __('Momsregistreringen är på gång'),
        };
    }

    /** The wording an invoice without VAT carries; null when there is none. */
    public function invoiceText(): ?string
    {
        return match ($this) {
            self::SmallBusiness => 'Undantagen från skatteplikt enligt 18 kap. 4 § mervärdesskattelagen (2023:200).',
            self::ExemptSupplies => 'Omsättningen är undantagen från skatteplikt för mervärdesskatt enligt 10 kap. mervärdesskattelagen (2023:200).',
            self::RegistrationPending => null,
        };
    }

    /** Whether the company owes VAT on its sales even though it is not registered. */
    public function owesVat(): bool
    {
        return $this === self::RegistrationPending;
    }
}
