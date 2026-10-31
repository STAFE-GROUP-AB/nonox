<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * Sales outside Sweden that carry no Swedish VAT (AGENTS.md §7 Phase 2,
 * §8.2 box E): each has its own BAS revenue account, which feeds its box of
 * the momsdeklaration, and a text the invoice must carry.
 *
 * TODO(legal-verify): the accounts (BAS 3108, 3308, 3105, 3305), the boxes
 * (35, 39, 36, 40), the invoice wording required by ML 17 kap., and that a
 * sale of services outside the EU is reported in box 40.
 */
enum ForeignSale: string
{
    case EuGoods = 'EU_GOODS';
    case EuServices = 'EU_SERVICES';
    case ExportGoods = 'EXPORT_GOODS';
    case ExportServices = 'EXPORT_SERVICES';

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
        };
    }

    public function accountName(): string
    {
        return match ($this) {
            self::EuGoods => 'Försäljning varor till annat EU-land, momsfri',
            self::EuServices => 'Försäljning tjänster till annat EU-land',
            self::ExportGoods => 'Försäljning varor till land utanför EU',
            self::ExportServices => 'Försäljning tjänster till land utanför EU',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::EuGoods => 'Varor till företag i annat EU-land',
            self::EuServices => 'Tjänster till företag i annat EU-land',
            self::ExportGoods => 'Export av varor utanför EU',
            self::ExportServices => 'Tjänster till kund utanför EU',
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
        };
    }

    public function isEu(): bool
    {
        return $this === self::EuGoods || $this === self::EuServices;
    }

    /** Whether the sale belongs in the periodisk sammanställning. */
    public function isReportedOnEuSalesList(): bool
    {
        return $this->isEu();
    }

    public static function isEuCountry(?string $country): bool
    {
        return in_array(strtoupper((string) $country), self::EU_COUNTRIES, true);
    }
}
