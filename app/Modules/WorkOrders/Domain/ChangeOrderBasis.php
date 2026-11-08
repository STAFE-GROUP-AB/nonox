<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

/**
 * Why an ÄTA has a price (KtjL 38 §, AO-R5). Shown on the document and kept.
 */
enum ChangeOrderBasis: string
{
    /** A change the customer asks for. */
    case ChangeOrdered = 'CHANGE_ORDERED';

    /** Tilläggsarbete under KtjL 8 §. */
    case AdditionalWork = 'ADDITIONAL_WORK_8';

    /** The job became more expensive because of the customer's circumstances (KtjL 38 § första stycket 2). */
    case CustomerCircumstances = 'CUSTOMER_CIRCUMSTANCES_38_2';

    public function label(): string
    {
        return match ($this) {
            self::ChangeOrdered => __('Ändring som kunden beställt'),
            self::AdditionalWork => __('Tilläggsarbete (8 § konsumenttjänstlagen)'),
            self::CustomerCircumstances => __('Förhållanden på kundens sida (38 § konsumenttjänstlagen)'),
        };
    }
}
