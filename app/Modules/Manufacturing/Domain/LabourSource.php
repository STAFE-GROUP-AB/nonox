<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Domain;

/**
 * Where an order's labour minutes come from (ADR-071 §8). Until the
 * workshop terminal exists (M5), an order is finished with the planned
 * minutes of its copy or with minutes entered on finishing; the terminal
 * writes its own rows, and an operation that has terminal minutes is not
 * topped up with planned ones.
 */
enum LabourSource: string
{
    /** The copy's standard minutes for the order, used when nothing else was reported. */
    case Planned = 'PLANNED';

    /** Minutes entered when the order was finished. */
    case Entered = 'ENTERED';

    /** Minutes reported in the workshop terminal (M5). */
    case Terminal = 'TERMINAL';

    public function label(): string
    {
        return match ($this) {
            self::Planned => __('Planerad tid'),
            self::Entered => __('Angiven vid färdigmeldning'),
            self::Terminal => __('Verkstadsterminalen'),
        };
    }
}
