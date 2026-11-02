<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * Sales outside Sweden that carry no Swedish VAT (AGENTS.md §7 Phase 2,
 * §8.2 box E): each has its own BAS revenue account, which feeds its box of
 * the momsdeklaration, and a text the invoice must carry.
 *
 * Two more kinds: an intermediary's sale in triangulation (trepartshandel,
 * box 38), and a sale to a consumer in another EU country under the One Stop
 * Shop, which carries the buyer country's VAT (OssRates) and is reported in
 * the OSS return, not in the Swedish momsdeklaration.
 *
 * TODO(legal-verify): the accounts (BAS 3108, 3308, 3105, 3305; 3107 for
 * triangulation and 3106 with VAT on 2619 for OSS are not confirmed against
 * BAS), the boxes (35, 39, 36, 40, 38), the invoice wording required by ML
 * 17 kap., and that a sale of services outside the EU is reported in box 40.
 */
enum ForeignSale: string
{
    case EuGoods = 'EU_GOODS';
    case EuServices = 'EU_SERVICES';
    case ExportGoods = 'EXPORT_GOODS';
    case ExportServices = 'EXPORT_SERVICES';
    case EuTriangular = 'EU_TRIANGULAR';
    case EuConsumerOss = 'EU_CONSUMER_OSS';

    /** The member states other than Sweden, as ISO 3166-1 alpha-2 codes. */
    public const array EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SI', 'SK',
    ];

    public function account(): int
    {
        return match ($this) {
            self::EuGoods => 3108,
            self::EuServices => 3308,
            self::ExportGoods => 3105,
            self::ExportServices => 3305,
            self::EuTriangular => 3107,
            self::EuConsumerOss => 3106,
        };
    }

    public function accountName(): string
    {
        return match ($this) {
            self::EuGoods => 'Försäljning varor till annat EU-land, momsfri',
            self::EuServices => 'Försäljning tjänster till annat EU-land',
            self::ExportGoods => 'Försäljning varor till land utanför EU',
            self::ExportServices => 'Försäljning tjänster till land utanför EU',
            self::EuTriangular => 'Försäljning varor till annat EU-land, trepartshandel',
            self::EuConsumerOss => 'Försäljning varor till annat EU-land, momspliktig',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::EuGoods => 'Varor till företag i annat EU-land',
            self::EuServices => 'Tjänster till företag i annat EU-land',
            self::ExportGoods => 'Export av varor utanför EU',
            self::ExportServices => 'Tjänster till kund utanför EU',
            self::EuTriangular => 'Trepartshandel inom EU, som mellanman',
            self::EuConsumerOss => 'Privatperson i annat EU-land (OSS)',
        };
    }

    /** The text on the invoice, in Swedish and English since the buyer is abroad. */
    public function invoiceText(): string
    {
        return match ($this) {
            self::EuGoods => 'Unionsintern leverans av varor, undantagen från moms. / Intra-Community supply of goods, exempt from VAT.',
            self::EuServices => 'Omvänd betalningsskyldighet. / Reverse charge.',
            self::ExportGoods => 'Export av varor, undantagen från moms. / Export of goods, exempt from VAT.',
            self::ExportServices => 'Tjänsten är omsatt utanför Sverige. / Supply of services outside Sweden, not subject to Swedish VAT.',
            self::EuTriangular => 'Trepartshandel. Förenklingsregeln för trepartshandel tillämpas; köparen redovisar momsen. / Triangulation. Simplified triangulation, VAT to be accounted for by the recipient.',
            self::EuConsumerOss => 'Moms enligt köparlandets regler, redovisas via OSS. / VAT of the customer\'s country, declared through the One Stop Shop.',
        };
    }

    public function isEu(): bool
    {
        return $this !== self::ExportGoods && $this !== self::ExportServices;
    }

    /** Whether the invoice carries no VAT at all (every kind but OSS). */
    public function isVatFree(): bool
    {
        return $this !== self::EuConsumerOss;
    }

    /** Whether the buyer must be a business with a VAT number from its country. */
    public function requiresBuyerVatNumber(): bool
    {
        return $this->isEu() && $this !== self::EuConsumerOss;
    }

    /** Whether the sale belongs in the periodisk sammanställning. */
    public function isReportedOnEuSalesList(): bool
    {
        return $this->requiresBuyerVatNumber();
    }

    /**
     * The column of the periodisk sammanställning: goods, triangulation or services.
     *
     * @return 'goods'|'triangular'|'services'|null
     */
    public function euSalesListColumn(): ?string
    {
        return match ($this) {
            self::EuGoods => 'goods',
            self::EuTriangular => 'triangular',
            self::EuServices => 'services',
            default => null,
        };
    }

    /**
     * Whether the sale is reported for the period the invoice is issued even
     * under kontantmetoden: an intra-Community supply of goods (ML 2023:200
     * 7 kap. 29 §) and services that go into the periodisk sammanställning
     * (7 kap. 30 §, the taxable event, which is the invoice date here).
     * Kontantmetoden (7 kap. 16 §) covers neither (enligt granskning
     * 2026-11-02, juristgranskning återstår (AUD-FAKT-4, #50)).
     *
     * TODO(legal-verify): whether the intermediary's sale in triangulation
     * (box 38) follows the same rule; it is booked at payment until then.
     */
    public function isReportedWhenInvoiced(): bool
    {
        return $this === self::EuGoods || $this === self::EuServices;
    }

    public static function isEuCountry(?string $country): bool
    {
        return in_array(strtoupper((string) $country), self::EU_COUNTRIES, true);
    }
}
