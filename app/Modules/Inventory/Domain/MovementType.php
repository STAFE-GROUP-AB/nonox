<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * What moved stock (ADR-039). Receipts, returns and counted surpluses bring
 * goods in at a cost; picks, POS sales and scrapping take them out; a
 * transfer is two rows (out and in) with the same transfer group. Kök
 * (ADR-073) adds drawing sold dishes' ingredients and the business's own
 * use.
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

    /** Kassation: broken, expired or lost goods written off; Kök's waste log with its reason (ADR-073 §6). */
    case Scrap = 'SCRAP';

    /** Arbetsorder: material taken from stock for a work order (out), or back after a reversal (in) (ADR-074 §3). */
    case WorkOrderIssue = 'WORK_ORDER_ISSUE';
    /** Såld via recept: the ingredients of a day's till sales of dishes, drawn through the recipes (ADR-073 §5). */
    case RecipeSale = 'RECIPE_SALE';

    /** Eget bruk: food used by the business itself, such as staff meals and representation (ADR-073 §7). */
    case InternalUse = 'INTERNAL_USE';

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
            self::WorkOrderIssue => __('Arbetsorder'),
            self::RecipeSale => __('Såld via recept'),
            self::InternalUse => __('Eget bruk'),
        };
    }

    /** Movements in of these types carry a cost that enters the valuation. */
    public function bringsCost(): bool
    {
        return $this !== self::Transfer;
    }
}
