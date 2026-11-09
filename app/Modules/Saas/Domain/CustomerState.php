<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/** A customer at a month-end. */
enum CustomerState: string
{
    /** MRR above zero. */
    case Active = 'ACTIVE';
    /** No MRR because every contract is paused, for at most 12 month-ends (SA-R9, O4). */
    case Paused = 'PAUSED';
    /** Had MRR before and has none now. */
    case Churned = 'CHURNED';
    /** Has a contract but no MRR yet, for example during a free trial (SA-R6). */
    case None = 'NONE';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Aktiv'),
            self::Paused => __('Pausad'),
            self::Churned => __('Avslutad'),
            self::None => __('Ingen MRR än'),
        };
    }
}
