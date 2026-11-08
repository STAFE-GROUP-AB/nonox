<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Domain;

/**
 * The meal a staff meal is (ADR-073 §7). Breakfast is valued as
 * KOSTFORMAN_FRUKOST, lunch and dinner as KOSTFORMAN_LUNCH (IL 61 kap.
 * 3 §: "lunch eller middag").
 */
enum MealType: string
{
    case Breakfast = 'BREAKFAST';
    case Lunch = 'LUNCH';
    case Dinner = 'DINNER';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => __('Frukost'),
            self::Lunch => __('Lunch'),
            self::Dinner => __('Middag'),
        };
    }

    /** The payroll pay code for one meal of this kind. */
    public function payCode(): string
    {
        return $this === self::Breakfast ? 'KOSTFORMAN_FRUKOST' : 'KOSTFORMAN_LUNCH';
    }
}
