<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Domain;

/**
 * Why food went to someone's own use (ADR-073 §7): a staff meal (a
 * taxable kostförmån with payroll on), internal representation (tax-free,
 * never sent to payroll, SKV kost), or the owner of a sole trader's own
 * meal. Stored as the INTERNAL_USE movement's reason.
 */
enum MealReason: string
{
    case Staff = 'PERSONALMAT';
    case Representation = 'REPRESENTATION';
    case Owner = 'AGARE';

    public function label(): string
    {
        return match ($this) {
            self::Staff => __('Personalmat'),
            self::Representation => __('Representation'),
            self::Owner => __('Ägarens måltid'),
        };
    }
}
