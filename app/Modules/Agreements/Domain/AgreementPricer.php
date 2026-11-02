<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

use App\Modules\Payroll\Domain\Engine\PayInput;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\Steps\Additions;
use App\Modules\Payroll\Domain\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Prices already split hours (manual lines and lines from a payroll file,
 * such as OB or overtime per kind) with the collective agreement's rate when
 * the line has no rate or amount of its own (ADR-033, gap G6/G17, step A3).
 * The rate is the step in force on the line's work date (else the first day
 * of the period) in the verified version that covers that day, and every
 * priced line carries the version, rate, basis and source in its
 * explanation. Pure.
 */
final class AgreementPricer
{
    public const string INCLUDED = 'INCLUDED';

    public const string INCLUDED_IN_SALARY = 'INCLUDED_IN_SALARY';

    /**
     * @return list<PayInput>
     *
     * @throws PayrollBlockedException
     */
    public function price(PayrollContext $context, AgreementContext $agreement): array
    {
        $priced = [];

        foreach ($context->inputs as $input) {
            foreach ($this->byRateStep($agreement, $input) as $part) {
                $priced[] = $this->priceOne($context, $agreement, $part);
            }
        }

        return $priced;
    }

    /**
     * A line that carries its hours per work date (time reports from
     * Planering) is split into one line per agreement version and rate step
     * in force on those dates, so hours on both sides of a new rate are each
     * priced at their own. Any other line is left as it is.
     *
     * @return list<PayInput>
     */
    private function byRateStep(AgreementContext $agreement, PayInput $input): array
    {
        $byDate = $input->explanation['hours_by_date'] ?? null;

        if (($input->explanation[Additions::AGREEMENT_PRICED] ?? false) !== true || ! is_array($byDate) || count($byDate) < 2 || $input->amountOre !== null || $input->rateOre !== null) {
            return [$input];
        }

        $groups = [];

        foreach ($byDate as $date => $hours) {
            $day = CarbonImmutable::parse((string) $date);
            $version = $agreement->versionOn($day);
            $step = $version === null ? null : $version['rules']->rate($input->code, $day);
            $groups[($version['id'] ?? 'none').'|'.($step['valid_from'] ?? 'none')][(string) $date] = (string) $hours;
        }

        if (count($groups) === 1) {
            return [$input];
        }

        return array_values(array_map(fn (array $dates): PayInput => new PayInput(
            $input->code,
            $input->description,
            $input->category,
            $input->taxable,
            $input->taxMethod,
            $input->contributionBasis,
            $input->vacationBasis,
            (string) array_reduce($dates, fn (BigDecimal $sum, string $hours): BigDecimal => $sum->plus($hours), BigDecimal::zero()),
            null,
            null,
            [...$input->explanation, 'date_from' => (string) array_key_first($dates), 'date_to' => (string) array_key_last($dates), 'hours_by_date' => $dates],
        ), $groups));
    }

    private function priceOne(PayrollContext $context, AgreementContext $agreement, PayInput $input): PayInput
    {
        if (($input->explanation[Additions::AGREEMENT_PRICED] ?? false) !== true || $input->amountOre !== null || $input->rateOre !== null || $input->quantity === null) {
            return $input;
        }

        $day = isset($input->explanation['date_from']) ? CarbonImmutable::parse((string) $input->explanation['date_from']) : $context->periodStart;
        $version = $agreement->versionOn($day)
            ?? throw new PayrollBlockedException(__(':item: ingen kontrollerad version av avtalet gäller :date.', ['item' => $input->description, 'date' => $day->toDateString()]));
        $rules = $version['rules'];
        $code = $rules->code($input->code)
            ?? throw new PayrollBlockedException(__('Lönearten :code hör inte till :agreement, som anställningen är knuten till.', ['code' => $input->code, 'agreement' => $rules->shortName()]));

        if (($code['kind'] ?? null) === 'OB' && $agreement->obCompensation === self::INCLUDED) {
            throw new PayrollBlockedException(__(':item: OB ingår i lönen enligt anställningens avtalsuppgifter. Ta bort raden eller ändra uppgiften under Lön → Avtal.', ['item' => $input->description]));
        }

        if (($code['kind'] ?? null) === 'OVERTIME' && $agreement->overtimeCompensation === self::INCLUDED_IN_SALARY) {
            throw new PayrollBlockedException(__(':item: övertid ingår i lönen enligt anställningens avtalsuppgifter. Ta bort raden eller ändra uppgiften under Lön → Avtal.', ['item' => $input->description]));
        }

        $step = $rules->rate($input->code, $day)
            ?? throw new PayrollBlockedException(__(':item: avtalet har ingen sats för :date.', ['item' => $input->description, 'date' => $day->toDateString()]));
        [$rate, $basis] = $this->rate($context, $rules, $step['rate'], $input->description);
        $source = (string) ($code['source'] ?? '');
        $document = $rules->source($source);

        return new PayInput(
            $input->code,
            $input->description,
            $input->category,
            $input->taxable,
            $input->taxMethod,
            $input->contributionBasis,
            $input->vacationBasis,
            $input->quantity,
            $rate,
            null,
            [...$input->explanation, 'agreement' => array_filter([
                'version_id' => $version['id'],
                'key' => $agreement->agreementKey,
                'variant' => $agreement->variant,
                'name' => $rules->shortName(),
                'version_valid_from' => $version['valid_from']->toDateString(),
                'code' => $input->code,
                'code_name' => (string) ($code['name'] ?? $input->code),
                'rate_from' => $step['valid_from'],
                'rate' => $step['rate'],
                'basis' => $basis,
                'source' => $source,
                'source_title' => $document['title'] ?? null,
                'source_url' => $document['url'] ?? null,
                'day_type' => $code['day_type'] ?? null,
            ], fn (mixed $value): bool => $value !== null)],
        );
    }

    /**
     * The öre per hour of an agreement code on a day, for a calculation
     * outside the pay lines (lost OB in sick pay, A8); null when the code has
     * no rate that day or it cannot be priced for this employment.
     */
    public function hourlyOre(PayrollContext $context, AgreementRules $rules, string $code, CarbonImmutable $day): ?int
    {
        $step = $rules->rate($code, $day);

        if ($step === null) {
            return null;
        }

        try {
            return $this->rate($context, $rules, $step['rate'], $code)[0];
        } catch (PayrollBlockedException) {
            return null;
        }
    }

    /**
     * The öre per hour, and what it was calculated from.
     *
     * @param  array<string, mixed>  $rate
     * @return array{int, array<string, mixed>}
     */
    private function rate(PayrollContext $context, AgreementRules $rules, array $rate, string $item): array
    {
        return match ($rate['type'] ?? null) {
            'ORE_PER_HOUR' => [(int) $rate['ore'], ['type' => 'fixed']],
            'PERCENT_OF_HOURLY' => $this->percentOfHourly($context, $rules, $rate, $item),
            'DIVISOR_OF_MONTHLY' => $this->divisorOfMonthly($context, $rate, $item),
            default => throw new PayrollBlockedException(__(':item: avtalets sats har en form som NONOX inte räknar ännu.', ['item' => $item])),
        };
    }

    /**
     * @param  array<string, mixed>  $rate
     * @return array{int, array<string, mixed>}
     */
    private function percentOfHourly(PayrollContext $context, AgreementRules $rules, array $rate, string $item): array
    {
        [$hourly, $basis] = $this->hourlyWage($context, $rules, $item);
        $percent = (int) $rate['bp'] + (($rate['plus_hourly_wage'] ?? false) === true ? Units::BASIS_POINTS : 0);

        return [
            $hourly->multipliedBy($percent)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt(),
            [...$basis, 'percent_bp' => $percent, 'hourly_wage_ore' => (string) $hourly->toScale(Units::SHOWN_DECIMALS, RoundingMode::HalfUp)],
        ];
    }

    /**
     * @param  array<string, mixed>  $rate
     * @return array{int, array<string, mixed>}
     */
    private function divisorOfMonthly(PayrollContext $context, array $rate, string $item): array
    {
        if ($context->salaryForm !== 'MONTHLY' || $context->monthlySalaryOre === null) {
            throw new PayrollBlockedException(__(':item: avtalets sats räknas på månadslönen, men anställningen har timlön.', ['item' => $item]));
        }

        return [
            BigDecimal::of($context->monthlySalaryOre)->dividedBy((string) $rate['divisor'], 0, RoundingMode::HalfUp)->toInt(),
            ['type' => 'monthly_divisor', 'monthly_salary_ore' => $context->monthlySalaryOre, 'divisor' => (string) $rate['divisor']],
        ];
    }

    /**
     * The hourly wage the agreement's percentages are taken of: the hourly
     * rate, or the monthly salary divided by the agreement's divisor; for
     * part time, the agreement's own formula when it has one.
     *
     * @return array{BigDecimal, array<string, mixed>}
     */
    private function hourlyWage(PayrollContext $context, AgreementRules $rules, string $item): array
    {
        if ($context->salaryForm === 'HOURLY') {
            if ($context->hourlyRateOre === null) {
                throw new PayrollBlockedException(__(':item: timlönen saknas på anställningen.', ['item' => $item]));
            }

            return [BigDecimal::of($context->hourlyRateOre), ['type' => 'hourly_rate']];
        }

        if ($context->monthlySalaryOre === null) {
            throw new PayrollBlockedException(__(':item: månadslönen saknas på anställningen.', ['item' => $item]));
        }

        $partTime = $rules->partTimeDivisor();

        if ($partTime !== null && $context->employmentRateBp < Units::BASIS_POINTS && $partTime['type'] === 'WEEKLY_HOURS_TIMES') {
            if ($context->weeklyHours === null || BigDecimal::of($context->weeklyHours)->isZero()) {
                throw new PayrollBlockedException(__(':item: veckoarbetstid saknas på anställningen. Den behövs för timlönen enligt avtalet.', ['item' => $item]));
            }

            $salary = BigDecimal::of($context->monthlySalaryOre)->multipliedBy($context->employmentRateBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp);
            $hours = BigDecimal::of($context->weeklyHours)->multipliedBy($partTime['factor']);

            return [
                $salary->dividedBy($hours, Units::SCALE, RoundingMode::HalfUp),
                ['type' => 'part_time', 'monthly_salary_ore' => (string) $salary->toScale(0, RoundingMode::HalfUp), 'weekly_hours' => $context->weeklyHours, 'factor' => $partTime['factor'], 'source' => $rules->hourlyDivisorSource()],
            ];
        }

        $divisor = $rules->hourlyDivisor()
            ?? throw new PayrollBlockedException(__(':item: avtalsdatan saknar delare från månadslön till timlön. Ange timpris på raden.', ['item' => $item]));

        return [
            BigDecimal::of($context->monthlySalaryOre)->dividedBy($divisor, Units::SCALE, RoundingMode::HalfUp),
            ['type' => 'monthly_divisor', 'monthly_salary_ore' => $context->monthlySalaryOre, 'divisor' => $divisor, 'source' => $rules->hourlyDivisorSource()],
        ];
    }
}
