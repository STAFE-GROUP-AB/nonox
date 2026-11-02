<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * What moved stock (ADR-039). Receipts, returns and counted surpluses bring
 * goods in at a cost; picks, POS sales and scrapping take them out; a
 * transfer is two rows (out and in) with the same transfer group.
 */
enum MovementType: string
{
    /** Inleverans: goods received from a supplier. */
    case Receipt = 'RECEIPT';

    /** Plock/utleverans: goods picked and sent to a customer. */
    case Pick = 'PICK';

    /** Flytt: between two locations of the company. */
    case Transfer = 'TRANSFER';

    /** Inventering: the difference between counted and system quantity. */
    case Count = 'COUNT';

    /** Kassa: a sale through a point of sale (Zettle, Onslip and others, ADR-039). */
    case PosSale = 'POS_SALE';

    /** Retur: goods back from a customer (in) or to a supplier (out). */
    case Return = 'RETURN';

    /** Kassation: broken, expired or lost goods written off. */
    case Scrap = 'SCRAP';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => __('Inleverans'),
            self::Pick => __('Plock'),
            self::Transfer => __('Flytt'),
            self::Count => __('Inventering'),
            self::PosSale => __('Kassa'),
            self::Return => __('Retur'),
            self::Scrap => __('Kassation'),
        };
    }

    /** Movements in of these types carry a cost that enters the valuation. */
    public function bringsCost(): bool
    {
        return $this !== self::Transfer;
    }
}
