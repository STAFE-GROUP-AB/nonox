<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

use App\Modules\Payroll\Domain\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What an hour and an average week of pay are worth for an employment:
 * the hourly rate, or the monthly salary × rate turned into a year and
 * spread over the weeks and the weekly hours.
 *
 * TODO(legal-verify): sjuklönelagen 6 § speaks of the pay during "en
 * genomsnittlig vecka"; the conversion monthly × 12 / 52 used here, and the
 * hourly value for deductions, must be confirmed (agreements may differ).
 */
final class HourlyValue
{
    /**
     * @throws PayrollBlockedException without weekly hours
     */
    public static function hour(PayrollContext $context): BigDecimal
    {
        if ($context->salaryForm === 'HOURLY') {
            return BigDecimal::of((int) $context->hourlyRateOre);
        }

        return self::week($context)->dividedBy(self::weeklyHours($context), Units::SCALE, RoundingMode::HalfUp);
    }

    /**
     * @throws PayrollBlockedException without weekly hours
     */
    public static function week(PayrollContext $context): BigDecimal
    {
        if ($context->salaryForm === 'HOURLY') {
            return BigDecimal::of((int) $context->hourlyRateOre)->multipliedBy(self::weeklyHours($context));
        }

        return BigDecimal::of((int) $context->monthlySalaryOre)
            ->multipliedBy($context->employmentRateBp)
            ->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp)
            ->multipliedBy(Units::MONTHS_PER_YEAR)
            ->dividedBy(Units::WEEKS_PER_YEAR, Units::SCALE, RoundingMode::HalfUp);
    }

    private static function weeklyHours(PayrollContext $context): BigDecimal
    {
        if ($context->weeklyHours === null || BigDecimal::of($context->weeklyHours)->isZero()) {
            throw new PayrollBlockedException(__('Veckoarbetstid saknas på anställningen. Den behövs för frånvaro och sjuklön.'));
        }

        return BigDecimal::of($context->weeklyHours);
    }
}
