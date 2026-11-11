<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Domain;

/**
 * What made the plan change (ADR-071 §13.5). ABSENCE is raised for every
 * kind of absence, so the type never tells sickness from holiday.
 */
enum DeviationType: string
{
    case Absence = 'ABSENCE';
    case PurchaseDelay = 'PURCHASE_DELAY';
    case Scrap = 'SCRAP';
    case Overrun = 'OVERRUN';
    case WorkstationDown = 'WORKSTATION_DOWN';
    case QuantityChange = 'QUANTITY_CHANGE';
    case MaterialShortage = 'MATERIAL_SHORTAGE';
    case LockBroken = 'LOCK_BROKEN';

    public function label(): string
    {
        return match ($this) {
            self::Absence => __('Frånvaro'),
            self::PurchaseDelay => __('Försenad leverans från leverantör'),
            self::Scrap => __('Kassation'),
            self::Overrun => __('Tar längre tid'),
            self::WorkstationDown => __('Arbetsstation ur drift'),
            self::QuantityChange => __('Ändrat antal eller datum'),
            self::MaterialShortage => __('Material saknas'),
            self::LockBroken => __('Låst arbete går inte att hålla'),
        };
    }
}
