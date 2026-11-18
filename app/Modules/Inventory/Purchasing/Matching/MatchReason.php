<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Matching;

/**
 * Why a row of the three-way comparison came out as it did (ADR-081 E11,
 * principle 4: every result carries its reasons). The first five put a row
 * outside the tolerances; the rest are information.
 */
enum MatchReason: string
{
    case NotReceived = 'NOT_RECEIVED';
    case NotConfirmed = 'NOT_CONFIRMED';
    case QuantityOver = 'QUANTITY_OVER';
    case PriceOver = 'PRICE_OVER';
    case OrderCancelled = 'ORDER_CANCELLED';
    case NoOrderRow = 'NO_ORDER_ROW';
    case SmallWithoutOrderRow = 'SMALL_WITHOUT_ORDER_ROW';
    case PartlyInvoiced = 'PARTLY_INVOICED';
    case PriceUnder = 'PRICE_UNDER';

    public function label(): string
    {
        return match ($this) {
            self::NotReceived => __('Inget har tagits emot på orderraden.'),
            self::NotConfirmed => __('Leveransen är inte bekräftad.'),
            self::QuantityOver => __('Fakturan tar betalt för fler än som har tagits emot.'),
            self::PriceOver => __('Priset är högre än i inköpsordern.'),
            self::OrderCancelled => __('Inköpsordern är makulerad.'),
            self::NoOrderRow => __('Fakturaraden hör inte till någon orderrad.'),
            self::SmallWithoutOrderRow => __('Rader utan orderrad, inom beloppsgränsen (till exempel frakt eller öresavrundning).'),
            self::PartlyInvoiced => __('Fakturan tar betalt för färre än som har tagits emot.'),
            self::PriceUnder => __('Priset är lägre än i inköpsordern.'),
        };
    }

    public function isDeviation(): bool
    {
        return in_array($this, [self::NotReceived, self::NotConfirmed, self::QuantityOver, self::PriceOver, self::OrderCancelled, self::NoOrderRow], true);
    }
}
