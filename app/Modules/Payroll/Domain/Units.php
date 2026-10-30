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
}
