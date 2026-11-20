<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Why a price in a mass price update is not changed (ADR-082 §8). Shown in
 * the preview's "fel" column as one Swedish sentence; stored as the code so
 * the line holds no free text.
 */
enum PriceUpdateRefusal: string
{
    case UnknownArticle = 'UNKNOWN_ARTICLE';
    case UnknownUnit = 'UNKNOWN_UNIT';
    case UnitNotConvertible = 'UNIT_NOT_CONVERTIBLE';
    case BadPrice = 'BAD_PRICE';
    case NegativePrice = 'NEGATIVE_PRICE';
    case PositiveToZero = 'POSITIVE_TO_ZERO';
    case PriceOrDiscount = 'PRICE_OR_DISCOUNT';
    case BadDiscount = 'BAD_DISCOUNT';
    case BadQuantity = 'BAD_QUANTITY';
    case BadDate = 'BAD_DATE';
    case DateInPast = 'DATE_IN_PAST';
    case Duplicate = 'DUPLICATE';
    case DiscountItem = 'DISCOUNT_ITEM';
    case BaseHasNoLadder = 'BASE_HAS_NO_LADDER';
    case DiscountOnBase = 'DISCOUNT_ON_BASE';
    case OtherCurrency = 'OTHER_CURRENCY';
    case SameStart = 'SAME_START';
    case FuturePriceExists = 'FUTURE_PRICE_EXISTS';
    case ChangedSince = 'CHANGED_SINCE';
    case ListArchived = 'LIST_ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::UnknownArticle => __('Det finns ingen artikel med det numret.'),
            self::UnknownUnit => __('Enheten finns inte i enhetsregistret.'),
            self::UnitNotConvertible => __('Enheten går inte att räkna om till artikelns enhet.'),
            self::BadPrice => __('Priset går inte att läsa. Skriv till exempel 149,00.'),
            self::NegativePrice => __('Priset blir lägre än noll.'),
            self::PositiveToZero => __('Priset skulle bli 0 kr. Ett pris över noll blir aldrig noll av en prisuppdatering; ändra det för hand om varan ska vara gratis.'),
            self::PriceOrDiscount => __('Fyll i antingen pris eller rabatt, inte båda och inte ingen.'),
            self::BadDiscount => __('Rabatten ska vara en procent mellan 0 och 100.'),
            self::BadQuantity => __('Från antal går inte att läsa.'),
            self::BadDate => __('Datumet går inte att läsa. Skriv det som 2027-01-01.'),
            self::DateInPast => __('Datumet har redan passerat. Ett nytt pris kan börja gälla i dag eller senare.'),
            self::Duplicate => __('Samma artikel, enhet och antal finns redan på en tidigare rad.'),
            self::DiscountItem => __('Priset är en rabatt på ordinarie pris och följer det redan.'),
            self::BaseHasNoLadder => __('Grundpriset har ingen antalstrappa och ingen annan enhet än artikelns.'),
            self::DiscountOnBase => __('Grundpriset kan inte vara en rabatt.'),
            self::OtherCurrency => __('Ett fast belopp i kronor ändrar inte priser i en annan valuta.'),
            self::SameStart => __('Ett annat pris börjar redan samma dag. Ändra det i listan, eller återställ den uppdatering som lade in det.'),
            self::FuturePriceExists => __('Det finns redan ett kommande pris. Ändra det i listan i stället.'),
            self::ChangedSince => __('Priset har ändrats sedan uppdateringen planerades. Gör en ny uppdatering för det.'),
            self::ListArchived => __('Listan är arkiverad.'),
        };
    }
}
