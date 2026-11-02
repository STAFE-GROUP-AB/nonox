<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Payroll\Domain\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * An estimate of what a shift costs the employer (PLANNING_MODULE.md §5):
 * the worked hours at the employee's hourly wage (a monthly salary turned
 * into an hourly wage by the employment's weekly hours), OB minutes at the
 * collective agreement's price, and vacation pay and employer contributions
 * only from verified payroll rules. What it cannot price is listed in
 * `missing` instead of being guessed. Payroll owns pay: the figure is an
 * estimate for planning, never a pay line. Pure.
 */
final class ShiftCost
{
    /**
     * @param  ?int  $contributionBp  employer contribution in basis points from a verified rule, null when it has none
     * @param  ?int  $vacationBp  vacation pay for hourly staff in basis points (procentregeln) from a verified rule
     * @param  bool  $agreementUnverified  the employment is bound to an agreement whose version is not checked
     */
    public static function estimate(
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $breakMinutes,
        ?string $salaryForm,
        ?int $hourlyRateOre,
        ?int $monthlySalaryOre,
        ?string $weeklyHours,
        ?AgreementRules $agreement = null,
        bool $agreementUnverified = false,
        bool $obIncludedInSalary = false,
        ?int $contributionBp = null,
        ?int $vacationBp = null,
    ): CostEstimate {
        $gross = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
        $worked = max(0, $gross - $breakMinutes);
        $missing = [];
        $notes = [];

        $hourly = self::hourlyWage($salaryForm, $hourlyRateOre, $monthlySalaryOre, $weeklyHours);

        if ($hourly === null) {
            return new CostEstimate($worked, null, 0, 0, null, null, [], [self::missingWage($salaryForm)], []);
        }

        $base = self::ore($hourly->multipliedBy($worked)->dividedBy(60, Units::SCALE, RoundingMode::HalfUp));
        [$ob, $obMinutes] = [0, []];

        if ($agreementUnverified) {
            $missing[] = __('OB: avtalsversionen är inte kontrollerad.');
        } elseif ($agreement !== null && ! $obIncludedInSalary) {
            [$ob, $obMinutes, $obMissing] = self::ob($agreement, $start, $end, $salaryForm, $hourlyRateOre, $monthlySalaryOre, $hourly);
            array_push($missing, ...$obMissing);

            if ($breakMinutes > 0 && $obMinutes !== []) {
                $notes[] = __('OB räknas på hela passet; rastens placering är inte planerad.');
            }
        }

        $vacation = null;

        if ($salaryForm === 'HOURLY') {
            $vacationBp === null
                ? $missing[] = __('Semesterersättning: ingen kontrollerad regel för procentregeln.')
                : $vacation = self::percent($base + $ob, $vacationBp);
        } else {
            $notes[] = __('Semestertillägg för månadslön ingår inte i uppskattningen.');
        }

        $contributions = null;

        if ($contributionBp === null) {
            $missing[] = __('Arbetsgivaravgifter: ingen kontrollerad regel.');
        } else {
            $contributions = self::percent($base + $ob + ($vacation ?? 0), $contributionBp);
        }

        return new CostEstimate($worked, self::ore($hourly), $base, $ob, $vacation, $contributions, $obMinutes, $missing, $notes);
    }

    /** The hourly wage in öre, with decimals: the hourly rate, or the monthly salary × 12 / 52 / weekly hours. */
    public static function hourlyWage(?string $salaryForm, ?int $hourlyRateOre, ?int $monthlySalaryOre, ?string $weeklyHours): ?BigDecimal
    {
        if ($salaryForm === 'HOURLY') {
            return $hourlyRateOre === null ? null : BigDecimal::of($hourlyRateOre);
        }

        if ($salaryForm !== 'MONTHLY' || $monthlySalaryOre === null || $weeklyHours === null || ! is_numeric($weeklyHours) || BigDecimal::of($weeklyHours)->isLessThanOrEqualTo(0)) {
            return null;
        }

        return BigDecimal::of($monthlySalaryOre)
            ->multipliedBy(Units::MONTHS_PER_YEAR)
            ->dividedBy(BigDecimal::of($weeklyHours)->multipliedBy(Units::WEEKS_PER_YEAR), Units::SCALE, RoundingMode::HalfUp);
    }

    /**
     * OB from the agreement's windows, priced per code with the rate step in
     * force on the day the shift starts.
     *
     * @return array{int, array<string, int>, list<string>}
     */
    private static function ob(AgreementRules $agreement, CarbonImmutable $start, CarbonImmutable $end, ?string $salaryForm, ?int $hourlyRateOre, ?int $monthlySalaryOre, BigDecimal $hourly): array
    {
        $split = ObSplitter::split($agreement, $start, $end, $hourlyRateOre, $monthlySalaryOre);
        $total = 0;
        $missing = [];

        foreach ($split->minutes as $code => $minutes) {
            $rate = $agreement->rate($code, $start)['rate'] ?? null;
            $perHour = $rate === null ? null : self::obRate($agreement, $rate, $salaryForm, $hourlyRateOre, $monthlySalaryOre, $hourly);

            if ($perHour === null) {
                $missing[] = __('OB :code: avtalet har ingen sats som kan räknas för passet.', ['code' => $code]);

                continue;
            }

            $total += self::ore($perHour->multipliedBy($minutes)->dividedBy(60, Units::SCALE, RoundingMode::HalfUp));
        }

        return [$total, $split->minutes, $missing];
    }

    /** @param  array<string, mixed>  $rate */
    private static function obRate(AgreementRules $agreement, array $rate, ?string $salaryForm, ?int $hourlyRateOre, ?int $monthlySalaryOre, BigDecimal $hourly): ?BigDecimal
    {
        return match ($rate['type'] ?? null) {
            'ORE_PER_HOUR' => BigDecimal::of((int) $rate['ore']),
            'PERCENT_OF_HOURLY' => self::agreementHourly($agreement, $salaryForm, $hourlyRateOre, $monthlySalaryOre, $hourly)
                ->multipliedBy((int) $rate['bp'])
                ->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp),
            'DIVISOR_OF_MONTHLY' => $salaryForm === 'MONTHLY' && $monthlySalaryOre !== null
                ? BigDecimal::of($monthlySalaryOre)->dividedBy((string) $rate['divisor'], Units::SCALE, RoundingMode::HalfUp)
                : null,
            default => null,
        };
    }

    /** The hourly wage the agreement's percentages are taken of: the hourly rate, or the monthly salary over the agreement's divisor. */
    private static function agreementHourly(AgreementRules $agreement, ?string $salaryForm, ?int $hourlyRateOre, ?int $monthlySalaryOre, BigDecimal $hourly): BigDecimal
    {
        if ($salaryForm === 'HOURLY' && $hourlyRateOre !== null) {
            return BigDecimal::of($hourlyRateOre);
        }

        $divisor = $agreement->hourlyDivisor();

        return $divisor !== null && $monthlySalaryOre !== null
            ? BigDecimal::of($monthlySalaryOre)->dividedBy($divisor, Units::SCALE, RoundingMode::HalfUp)
            : $hourly;
    }

    private static function percent(int $ore, int $bp): int
    {
        return self::ore(BigDecimal::of($ore)->multipliedBy($bp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp));
    }

    private static function ore(BigDecimal $value): int
    {
        return $value->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    private static function missingWage(?string $salaryForm): string
    {
        return match ($salaryForm) {
            'HOURLY' => __('Timlön saknas på anställningen.'),
            'MONTHLY' => __('Månadslön eller veckoarbetstid saknas på anställningen.'),
            default => __('Ingen pågående anställning med lön.'),
        };
    }
}
