<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * Units the calculation engine works in, kept outside the engine so the
 * engine itself holds no numbers (PAYROLL_MODULE.md B.17).
 */
final class Units
{
    /** Basis points in a whole: 10 000 bp = 100 %. */
    public const int BASIS_POINTS = 10000;

    /** Öre in a krona. */
    public const int ORE = 100;

    public const int MONTHS_PER_YEAR = 12;

    public const int WEEKS_PER_YEAR = 52;

    /** Decimals kept in intermediate divisions, before the final rounding to öre. */
    public const int SCALE = 10;

    /** Decimals shown for öre values in explanations. */
    public const int SHOWN_DECIMALS = 2;

    public const int MINUTES_PER_HOUR = 60;
}
