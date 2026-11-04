<?php

declare(strict_types=1);

namespace App\Domain\Company;

/**
 * What the company does, as the company itself states it (ADR-051). It
 * decides which steps of the start plan and which tips of Företagsguiden
 * apply. For an aktiebolag or a förening it describes the company; for an
 * enskild firma, or a person who owns a handels- or kommanditbolag, it is
 * also personal data about the owner (docs/gdpr/registerforteckning.md).
 * Every case but Other has a public industry page in docs/branscher.
 */
enum Industry: string
{
    case Cafe = 'cafe';
    case Restaurant = 'restaurant';
    case Crafts = 'crafts';
    case Consultant = 'consultant';
    case Retail = 'retail';
    case HairBeauty = 'hair_beauty';
    case AccountingFirm = 'accounting_firm';
    case FinanceConsultant = 'finance_consultant';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cafe => __('Kafé'),
            self::Restaurant => __('Restaurang'),
            self::Crafts => __('Hantverk och bygg'),
            self::Consultant => __('Konsult'),
            self::Retail => __('Butik och e-handel'),
            self::HairBeauty => __('Frisör och skönhet'),
            self::AccountingFirm => __('Redovisningsbyrå'),
            self::FinanceConsultant => __('Ekonomikonsult'),
            self::Other => __('Annan bransch'),
        };
    }

    /** The slug of the public industry page (docs/branscher), or null when there is none. */
    public function landingSlug(): ?string
    {
        return match ($this) {
            self::Cafe => 'bokforing-for-cafeer',
            self::Restaurant => 'bokforing-for-restauranger',
            self::Crafts => 'bokforing-for-hantverkare',
            self::Consultant => 'bokforing-for-konsulter',
            self::Retail => 'bokforing-for-butiker',
            self::HairBeauty => 'bokforing-for-frisorer',
            self::AccountingFirm => 'for-redovisningsbyraer',
            self::FinanceConsultant => 'for-ekonomikonsulter',
            self::Other => null,
        };
    }

    /**
     * The industries an article or a start plan step can be written for:
     * every one but Other.
     *
     * @return list<string>
     */
    public static function guideKeys(): array
    {
        return array_map(fn (self $industry): string => $industry->value, array_filter(self::cases(), fn (self $industry): bool => $industry !== self::Other));
    }
}
