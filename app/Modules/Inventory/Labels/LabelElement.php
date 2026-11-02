<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

/**
 * The things a label template can show. Some exist only on article labels
 * (price, VAT note, comparison price, SKU, brand, properties).
 */
enum LabelElement: string
{
    case Name = 'name';
    case Code = 'code';
    case Price = 'price';
    case VatNote = 'vat_note';
    case ComparisonPrice = 'comparison_price';
    case Barcode = 'barcode';
    case Qr = 'qr';
    case Sku = 'sku';
    case Brand = 'brand';
    case Properties = 'properties';
    case Logo = 'logo';
    case FreeText = 'free_text';

    public function label(LabelKind $kind = LabelKind::Article): string
    {
        return match ($this) {
            self::Name => $kind === LabelKind::Location ? __('Platsens namn') : __('Artikelnamn'),
            self::Code => $kind === LabelKind::Location ? __('Platskod') : __('Artikelnummer'),
            self::Price => __('Pris inkl. moms'),
            self::VatNote => __('Texten "inkl. moms"'),
            self::ComparisonPrice => __('Jämförpris'),
            self::Barcode => __('Streckkod'),
            self::Qr => __('QR-kod'),
            self::Sku => __('SKU'),
            self::Brand => __('Varumärke'),
            self::Properties => __('Valda egenskaper'),
            self::Logo => __('Företagets logotyp'),
            self::FreeText => __('Egen text'),
        };
    }

    /** Whether the element shows a selling price (never printed by those who may not see prices). */
    public function isPrice(): bool
    {
        return in_array($this, [self::Price, self::VatNote, self::ComparisonPrice], true);
    }

    /** @return list<self> */
    public static function for(LabelKind $kind): array
    {
        return $kind === LabelKind::Location
            ? [self::Code, self::Name, self::Barcode, self::Qr, self::Logo, self::FreeText]
            : self::cases();
    }
}
