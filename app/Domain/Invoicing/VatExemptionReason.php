<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * Why a row sold in Sweden carries 0 % VAT. A full invoice must refer to the
 * provision that exempts the supply, or otherwise state that it is exempt
 * (Mervärdesskattelag (2023:200) 17 kap. 24 § p. 13), and show the base per
 * exemption (p. 9). The text is kept on the row when the draft is saved, so
 * an issued invoice always prints what it was issued with (enligt granskning
 * 2026-11-02, juristgranskning återstår (AUD-FAKT-6, #54)).
 *
 * The provisions are from ML 10 kap., read 2026-11-02 at
 * https://data.riksdagen.se/dokument/sfs-2023-200.text. Other is the
 * general statement 24 § p. 13 c allows.
 *
 * TODO(legal-verify): the wording, and whether OutsideScope (for example an
 * outlay re-invoiced in the customer's name) is described correctly.
 */
enum VatExemptionReason: string
{
    case Other = 'OTHER';
    case Healthcare = 'HEALTHCARE';
    case SocialCare = 'SOCIAL_CARE';
    case Education = 'EDUCATION';
    case Sports = 'SPORTS';
    case Culture = 'CULTURE';
    case Insurance = 'INSURANCE';
    case Financial = 'FINANCIAL';
    case RealEstate = 'REAL_ESTATE';
    case OutsideScope = 'OUTSIDE_SCOPE';

    public function label(): string
    {
        return match ($this) {
            self::Other => __('Momsfri försäljning, annat undantag'),
            self::Healthcare => __('Sjukvård eller tandvård (10 kap. 6 §)'),
            self::SocialCare => __('Social omsorg (10 kap. 14 §)'),
            self::Education => __('Utbildning (10 kap. 15–18 §§)'),
            self::Sports => __('Idrott (10 kap. 28 §)'),
            self::Culture => __('Kultur (10 kap. 30 §)'),
            self::Insurance => __('Försäkring (10 kap. 32 §)'),
            self::Financial => __('Bank och finans (10 kap. 33 §)'),
            self::RealEstate => __('Fastighet, uthyrning av lokal eller bostad (10 kap. 35 §)'),
            self::OutsideScope => __('Utanför momsen, till exempel utlägg'),
        };
    }

    /** The text printed on the invoice. */
    public function invoiceText(): string
    {
        return match ($this) {
            self::Other => 'Undantagen från skatteplikt för mervärdesskatt.',
            self::Healthcare => 'Undantagen från skatteplikt enligt 10 kap. 6 § mervärdesskattelagen (2023:200).',
            self::SocialCare => 'Undantagen från skatteplikt enligt 10 kap. 14 § mervärdesskattelagen (2023:200).',
            self::Education => 'Undantagen från skatteplikt enligt 10 kap. 15–18 §§ mervärdesskattelagen (2023:200).',
            self::Sports => 'Undantagen från skatteplikt enligt 10 kap. 28 § mervärdesskattelagen (2023:200).',
            self::Culture => 'Undantagen från skatteplikt enligt 10 kap. 30 § mervärdesskattelagen (2023:200).',
            self::Insurance => 'Undantagen från skatteplikt enligt 10 kap. 32 § mervärdesskattelagen (2023:200).',
            self::Financial => 'Undantagen från skatteplikt enligt 10 kap. 33 § mervärdesskattelagen (2023:200).',
            self::RealEstate => 'Undantagen från skatteplikt enligt 10 kap. 35 § mervärdesskattelagen (2023:200).',
            self::OutsideScope => 'Utanför mervärdesskattens tillämpningsområde (ingen omsättning enligt mervärdesskattelagen).',
        };
    }
}
