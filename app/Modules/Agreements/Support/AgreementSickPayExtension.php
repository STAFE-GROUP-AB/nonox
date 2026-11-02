<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Support;

use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Domain\AgreementContext;
use App\Modules\Agreements\Domain\AgreementPricer;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Domain\TimeRounding;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Engine\AbsenceInput;
use App\Modules\Payroll\Domain\Engine\LongSickTerms;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\SickPayTerms;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\Run\ContextExtension;
use App\Modules\Planning\Domain\ObSplitter;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sick pay by the collective agreement and the published schedule (A8,
 * gap G10–G12 in docs/avtal/gap-analys.md). Runs after
 * AgreementContextExtension, for an employment bound to an agreement:
 *
 * - Lost OB: on each sick day, the OB the published shifts in Planering
 *   would have paid (ObSplitter with the agreement's OB windows and
 *   rounding, priced at the agreement's rate), when the agreement data says
 *   OB is part of the sick pay (sick_pay.includes_ob) and OB is not
 *   included in the salary.
 * - The average week behind the karensavdrag: the published schedule's OB
 *   and, without weekly hours on the employment (irregular hours), its
 *   hours, averaged over a reference period from the Monday of the first
 *   sick day: Planering's calculation period, at least four weeks.
 * - Long sickness: the agreement's deduction per calendar day after the
 *   employer's period (sick_pay.after_employer_period), with the threshold
 *   in prisbasbelopp from the payroll rules.
 *
 * TODO(legal-verify): sjuklönelagen 6 § speaks of what the employee
 * "genomsnittligen beräknas få under en vecka"; whether the published
 * schedule over that reference period is a representative period for
 * irregular hours (the agreements say "representativ period" or "per månad
 * eller cykel"), and which day decides the duration group of the long-sick
 * deduction. Without Planering nothing here applies and the law's
 * calculation in the SickPay step stands.
 *
 * Loads the run's published shifts in one query.
 */
final class AgreementSickPayExtension implements ContextExtension
{
    public const int MIN_REFERENCE_WEEKS = 4;

    private ?int $preparedRun = null;

    private bool $enabled = false;

    private int $referenceWeeks = self::MIN_REFERENCE_WEEKS;

    /** @var array<int, list<Shift>> */
    private array $shifts = [];

    public function __construct(private readonly ModuleManager $modules, private readonly AgreementPricer $pricer) {}

    public function prepare(PayrollRun $run, Collection $employments): void
    {
        $this->preparedRun = $run->id;
        $this->shifts = [];
        $company = $run->company;
        $this->enabled = $company !== null
            && $this->modules->isEnabled(AgreementsModule::KEY, $company)
            && $this->modules->isEnabled(PlanningModule::KEY, $company);

        if (! $this->enabled || $company === null) {
            return;
        }

        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));
        $this->referenceWeeks = max(self::MIN_REFERENCE_WEEKS, PlanningSettings::int($settings, 'overtime_period_weeks'));
        $ids = $employments->pluck('employee_id')->unique()->values()->all();

        if ($ids === []) {
            return;
        }

        $from = $run->periodStart()->subWeek()->startOfDay();
        $to = $run->periodEnd()->addWeeks($this->referenceWeeks + 1)->endOfDay();

        foreach (Shift::query()->whereIn('employee_id', $ids)->where('status', Shift::PUBLISHED)->where('starts_at', '>=', $from)->where('starts_at', '<=', $to)->orderBy('starts_at')->get() as $shift) {
            $this->shifts[(int) $shift->employee_id][] = $shift;
        }
    }

    public function extend(PayrollContext $context, PayrollRun $run, Employment $employment): PayrollContext
    {
        if ($this->preparedRun !== $run->id) {
            $this->prepare($run, collect([$employment]));
        }

        $agreement = $context->extension(AgreementContext::KEY);
        $sick = array_values(array_filter($context->absences, fn (AbsenceInput $absence): bool => $absence->type === 'SICK'));

        if (! $this->enabled || ! $agreement instanceof AgreementContext || $sick === []) {
            return $context;
        }

        $first = $sick[0]->date;

        foreach ($sick as $day) {
            $first = $day->date->lessThan($first) ? $day->date : $first;
        }

        $version = $agreement->versionOn($first) ?? $agreement->versionOn($context->periodEnd);

        if ($version === null) {
            return $context;
        }

        $rules = $version['rules'];
        $shifts = $this->shifts[$employment->employee_id] ?? [];
        $withOb = $rules->obInSickPay() && $agreement->obCompensation !== AgreementPricer::INCLUDED;
        $absences = [];

        foreach ($context->absences as $absence) {
            $planned = $absence->type === 'SICK' ? $this->onDay($shifts, $absence->date) : [];

            if ($planned === []) {
                $absences[] = $absence;

                continue;
            }

            // Sick on a day with published shifts: the hours missed are the schedule's, whatever the weekly pattern says.
            // A part of a day (fewer hours registered than the shifts) keeps its hours, and the OB in proportion.
            $minutes = array_sum(array_map(fn (Shift $shift): int => max(0, intdiv($shift->ends_at->getTimestamp() - $shift->starts_at->getTimestamp(), 60) - $shift->break_minutes), $planned));
            $given = BigDecimal::of($absence->hours)->multipliedBy(60);
            $partial = $given->isPositive() && $given->isLessThan($minutes);
            $hours = $partial ? $absence->hours : (string) BigDecimal::of($minutes)->dividedBy(60, 2, RoundingMode::HalfUp);
            [$ore, $parts] = $withOb ? $this->ob($context, $agreement, $planned) : [0, []];
            $ore = $partial && $minutes > 0 ? $given->multipliedBy($ore)->dividedBy($minutes, 0, RoundingMode::HalfUp)->toInt() : $ore;
            $absences[] = new AbsenceInput($absence->type, $absence->date, $hours, $ore, [...$parts, 'scheduled_minutes' => $minutes, 'partial' => $partial]);
        }

        [$weeklyOb, $weeklyHours, $window] = $this->averages($context, $agreement, $shifts, $first, $withOb);

        $terms = new SickPayTerms(
            averageWeeklySupplementsOre: $weeklyOb,
            averageWeeklyHours: $context->weeklyHours === null ? $weeklyHours : null,
            longSick: $this->longSick($context, $agreement, $rules, $first),
            explanation: array_filter([
                'agreement' => $rules->shortName(),
                'agreement_version_id' => $version['id'],
                'source' => (string) ($rules->sickPay()['source'] ?? ''),
                'reference_period' => $window,
            ]),
        );

        return $context->withSickPay($absences, $terms);
    }

    /**
     * The published shifts that start on a day (Stockholm wall clock).
     *
     * @param  list<Shift>  $shifts
     * @return list<Shift>
     */
    private function onDay(array $shifts, CarbonImmutable $day): array
    {
        $date = $day->toDateString();

        return array_values(array_filter($shifts, fn (Shift $shift): bool => $shift->starts_at->setTimezone(ObSplitter::TIMEZONE)->toDateString() === $date));
    }

    /**
     * The OB of shifts, in öre, priced by the agreement version in force on
     * each shift's day, and per code the minutes and öre.
     *
     * @param  list<Shift>  $shifts
     * @return array{int, array<string, array{minutes: int, ore: int}>}
     */
    private function ob(PayrollContext $context, AgreementContext $agreement, array $shifts): array
    {
        $total = 0;
        $parts = [];

        foreach ($shifts as $shift) {
            $day = $shift->starts_at->setTimezone(ObSplitter::TIMEZONE)->startOfDay();
            $rules = $agreement->versionOn($day)['rules'] ?? null;

            if (! $rules instanceof AgreementRules) {
                continue;
            }

            $split = ObSplitter::split($rules, $shift->starts_at, $shift->ends_at, $context->hourlyRateOre, $context->monthlySalaryOre);

            foreach ($split->minutes as $code => $minutes) {
                $minutes = TimeRounding::round($minutes, $rules->rounding('OB'));
                $rate = $this->pricer->hourlyOre($context, $rules, $code, $day);

                if ($rate === null || $minutes <= 0) {
                    continue;
                }

                $ore = BigDecimal::of($rate)->multipliedBy($minutes)->dividedBy(60, 0, RoundingMode::HalfUp)->toInt();
                $total += $ore;
                $parts[$code] = ['minutes' => ($parts[$code]['minutes'] ?? 0) + $minutes, 'ore' => ($parts[$code]['ore'] ?? 0) + $ore];
            }
        }

        return [$total, $parts];
    }

    /**
     * The average weekly OB and hours of the published schedule over the
     * reference period from the Monday of the first sick day; nulls when
     * the period has no published shifts.
     *
     * @param  list<Shift>  $shifts
     * @return array{?int, ?string, ?array{from: string, to: string, weeks: int}}
     */
    private function averages(PayrollContext $context, AgreementContext $agreement, array $shifts, CarbonImmutable $first, bool $withOb): array
    {
        $from = CarbonImmutable::parse($first->toDateString(), ObSplitter::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
        $to = $from->addWeeks($this->referenceWeeks);
        $inWindow = array_values(array_filter($shifts, fn (Shift $shift): bool => $shift->starts_at->greaterThanOrEqualTo($from) && $shift->starts_at->lessThan($to)));

        if ($inWindow === []) {
            return [null, null, null];
        }

        $minutes = array_sum(array_map(fn (Shift $shift): int => max(0, intdiv($shift->ends_at->getTimestamp() - $shift->starts_at->getTimestamp(), 60) - $shift->break_minutes), $inWindow));
        $ob = $withOb ? $this->ob($context, $agreement, $inWindow)[0] : 0;

        return [
            $withOb ? intdiv($ob + intdiv($this->referenceWeeks, 2), $this->referenceWeeks) : null,
            (string) BigDecimal::of($minutes)->dividedBy(60 * $this->referenceWeeks, 2, RoundingMode::HalfUp),
            ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(), 'weeks' => $this->referenceWeeks],
        ];
    }

    private function longSick(PayrollContext $context, AgreementContext $agreement, AgreementRules $rules, CarbonImmutable $first): ?LongSickTerms
    {
        $after = $rules->sickPay()['after_employer_period'] ?? null;

        if (! is_array($after) || $context->salaryForm !== 'MONTHLY') {
            return null;
        }

        $deduction = (array) ($after['deduction'] ?? []);
        $months = (int) $agreement->employmentStart->diffInMonths($first);
        $toDay = null;

        foreach ((array) ($after['duration'] ?? []) as $duration) {
            $duration = (array) $duration;

            if ($months >= (int) ($duration['min_employment_months'] ?? 0)) {
                $toDay = (int) $duration['to_day'];

                break;
            }
        }

        if ($toDay === null) {
            return null;
        }

        $pbb = (int) ((array) $context->rules->get('prisbasbelopp'))['ore'];
        $threshold = BigDecimal::of($pbb)->multipliedBy((string) $deduction['threshold_prisbasbelopp'])->toScale(0, RoundingMode::HalfUp)->toInt();

        if ($threshold <= 0) {
            throw new PayrollBlockedException(__('Prisbasbeloppet saknas i lönereglerna, så avtalets sjukavdrag efter dag 14 kan inte räknas.'));
        }

        return new LongSickTerms(
            fromDay: (int) $after['from_day'],
            toDay: $toDay,
            belowBp: (int) $deduction['below_bp'],
            aboveBp: (int) $deduction['above_bp'],
            thresholdYearlyOre: $threshold,
            source: (string) ($after['source'] ?? ''),
            daysPerYear: (int) $deduction['days_per_year'],
        );
    }
}
