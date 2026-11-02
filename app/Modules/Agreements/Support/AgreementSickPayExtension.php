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
use App\Modules\Payroll\Domain\Engine\KarensTerms;
use App\Modules\Payroll\Domain\Engine\LongSickTerms;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\SickHistory;
use App\Modules\Payroll\Domain\Engine\SickHourValue;
use App\Modules\Payroll\Domain\Engine\SickPayTerms;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Support\Run\ContextBuilder;
use App\Modules\Payroll\Support\Run\ContextExtension;
use App\Modules\Planning\Domain\ObSplitter;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sick pay by the collective agreement (A8, gap G10–G12 in
 * docs/avtal/gap-analys.md). Runs after AgreementContextExtension, for an
 * employment bound to an agreement. The agreement version and the
 * duration group are those in force on the first day of the sick period
 * (sjuklöneperiod), which may lie in an earlier month.
 *
 * From the agreement data alone (also without Planering):
 * - the agreement's own hour (or day) value of sick pay
 *   (sick_pay.hour_value) and karensavdrag counted in time
 *   (sick_pay.karens), as sjuklönelagen 2 § andra stycket allows;
 * - long sickness: the agreement's deduction per calendar day after the
 *   employer's period (sick_pay.after_employer_period), with the threshold
 *   in prisbasbelopp from the payroll rules, the duration group (a year's
 *   employment or a direct transfer from an employment with sick pay to day
 *   90), the ceiling of days in twelve months and the agreement's
 *   "månadslön" (fixed supplements on the employment, the monthly average
 *   of variable pay from approved payslips).
 *
 * With Planering on as well:
 * - Lost OB: on each sick day, the OB the published shifts would have paid
 *   (ObSplitter with the agreement's OB windows and rounding, priced at the
 *   agreement's rate), when the data says OB is part of the sick pay and OB
 *   is not included in the salary.
 * - The average week behind the karensavdrag (prop. 2017/18:96 s. 68): the
 *   attested time and OB of up to thirteen calendar weeks before the sick
 *   period's first week, only weeks the person was employed and not weeks
 *   with nothing but absence. Without such history the published schedule
 *   from the sick period's first week, averaged over the weeks that have
 *   shifts. Without either and without weekly hours, the run is blocked.
 *
 * Enligt AI-utredning 2026-11-02, juristgranskning återstår.
 * TODO(legal-verify): which weeks are representative ("genomsnittligen",
 * sjuklönelagen 6 §; the agreements say "representativ period" or "per
 * månad eller cykel"); whether public holidays count in the month's
 * working hours (Gröna Riks); Detaljhandeln's karens time valued per hour;
 * kost and bostad in the agreement's "månadslön" (a payslip note asks to
 * check); part-time sick leave from day 15.
 *
 * Queries per run, never per employee: the published shifts, the attested
 * days and absences before the run, and the variable pay of approved
 * payslips.
 */
final class AgreementSickPayExtension implements ContextExtension
{
    public const int MIN_REFERENCE_WEEKS = 4;

    /** About three months of history before the sick period (prop. 2017/18:96 s. 68: "en till tre månader"). */
    public const int HISTORY_WEEKS = 13;

    /** How many weeks before the run the history is loaded, to cover a sick period that started in the month before. */
    private const int HISTORY_MARGIN_WEEKS = 6;

    /** Detaljhandeln § 13.1: above this many scheduled days a month the deduction per day is the salary divided by the days. */
    private const int DAY_PERCENT_MAX_DAYS = 22;

    private const array MONTHLY_SALARY_PARTS = ['FIXED_SUPPLEMENTS', 'VARIABLE_AVERAGE', 'BENEFITS'];

    private ?int $preparedRun = null;

    private bool $agreements = false;

    private bool $planning = false;

    private int $referenceWeeks = self::MIN_REFERENCE_WEEKS;

    private ?CarbonImmutable $historyFrom = null;

    /** @var array<int, list<Shift>> */
    private array $shifts = [];

    /** @var array<int, list<Attestation>> */
    private array $attested = [];

    /** @var array<int, array<string, true>> days with approved absence before the run, by employee */
    private array $absent = [];

    /** @var array<int, list<array{period: string, code: string, ore: int}>> */
    private array $variable = [];

    /** @var array<int, true> employees whose history before an old sick period has been read */
    private array $historyLoaded = [];

    public function __construct(private readonly ModuleManager $modules, private readonly AgreementPricer $pricer) {}

    public function prepare(PayrollRun $run, Collection $employments): void
    {
        $this->preparedRun = $run->id;
        $this->shifts = [];
        $this->attested = [];
        $this->absent = [];
        $this->variable = [];
        $this->historyLoaded = [];
        $company = $run->company;
        $this->agreements = $company !== null && $this->modules->isEnabled(AgreementsModule::KEY, $company);
        $this->planning = $this->agreements && $company !== null && $this->modules->isEnabled(PlanningModule::KEY, $company);
        $ids = $employments->pluck('employee_id')->unique()->values()->all();

        if (! $this->agreements || $company === null || $ids === []) {
            return;
        }

        $periodStart = $run->periodStart();

        // Variable pay of approved payslips, per month and pay code, for the agreement's "månadslön" from day 15.
        foreach (PayslipLine::query()
            ->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->join('pay_codes', 'pay_codes.id', '=', 'payslip_lines.pay_code_id')
            ->whereIn('payslips.employee_id', $ids)
            ->whereNotIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->where('payroll_runs.period', '>=', $periodStart->subMonths(ContextBuilder::SICK_HISTORY_MONTHS)->format('Y-m'))
            ->where('payroll_runs.period', '<', $periodStart->format('Y-m'))
            ->groupBy('payslips.employee_id', 'payroll_runs.period', 'pay_codes.code')
            ->selectRaw('payslips.employee_id as employee_id, payroll_runs.period as period, pay_codes.code as code, sum(payslip_lines.amount_ore) as ore')
            ->toBase()
            ->get() as $row) {
            $this->variable[(int) $row->employee_id][] = ['period' => (string) $row->period, 'code' => (string) $row->code, 'ore' => (int) $row->ore];
        }

        if (! $this->planning) {
            return;
        }

        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));
        $this->referenceWeeks = max(self::MIN_REFERENCE_WEEKS, PlanningSettings::int($settings, 'overtime_period_weeks'));
        $from = $periodStart->subWeeks(self::HISTORY_MARGIN_WEEKS)->startOfDay();
        $to = $run->periodEnd()->addWeeks($this->referenceWeeks + 1)->endOfDay();

        foreach (Shift::query()->whereIn('employee_id', $ids)->where('status', Shift::PUBLISHED)->where('starts_at', '>=', $from)->where('starts_at', '<=', $to)->orderBy('starts_at')->get() as $shift) {
            $this->shifts[(int) $shift->employee_id][] = $shift;
        }

        $this->historyFrom = $periodStart->subWeeks(self::HISTORY_WEEKS + self::HISTORY_MARGIN_WEEKS)->startOfWeek(CarbonImmutable::MONDAY);

        foreach (Attestation::query()->whereIn('employee_id', $ids)->whereIn('status', [Attestation::ATTESTED, Attestation::SENT])
            ->whereDate('date', '>=', $this->historyFrom->toDateString())->whereDate('date', '<=', $run->periodEnd()->toDateString())
            ->orderBy('date')->get(['id', 'employee_id', 'date', 'actual_minutes', 'details']) as $row) {
            $this->attested[(int) $row->employee_id][] = $row;
        }

        // Absence up to the run's end: a sick period that starts late in the month has weeks of this month in its history.
        foreach (Absence::query()->whereIn('employee_id', $ids)->where('status', 'APPROVED')
            ->whereDate('date_from', '<=', $run->periodEnd()->toDateString())->whereDate('date_to', '>=', $this->historyFrom->toDateString())
            ->get(['employee_id', 'date_from', 'date_to']) as $absence) {
            for ($day = $absence->date_from->max($this->historyFrom); $day->lessThanOrEqualTo($run->periodEnd()) && $day->lessThanOrEqualTo($absence->date_to); $day = $day->addDay()) {
                $this->absent[(int) $absence->employee_id][$day->toDateString()] = true;
            }
        }
    }

    public function extend(PayrollContext $context, PayrollRun $run, Employment $employment): PayrollContext
    {
        if ($this->preparedRun !== $run->id) {
            $this->prepare($run, collect([$employment]));
        }

        $agreement = $context->extension(AgreementContext::KEY);
        $sick = array_values(array_filter($context->absences, fn (AbsenceInput $absence): bool => $absence->type === 'SICK'));

        if (! $this->agreements || ! $agreement instanceof AgreementContext || $sick === []) {
            return $context;
        }

        $periodStart = $this->periodStart($context, $sick);
        $rules = $this->rulesOn($agreement, $periodStart, $context);

        if ($rules === null) {
            return $context;
        }

        $facts = ['variable_monthly_ore' => $this->variableAverage($context, $employment->employee_id, $rules, $periodStart)];

        if (! $this->planning) {
            return $this->applyTerms($context, $agreement, $facts);
        }

        if ($this->historyFrom !== null && ! isset($this->historyLoaded[$employment->employee_id]) && $periodStart->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(self::HISTORY_WEEKS)->lessThan($this->historyFrom)) {
            // A sick period that started long before the run: read its history on its own (rare: a long sickness).
            $this->loadHistory($employment->employee_id, $periodStart);
        }

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
            $minutes = array_sum(array_map(fn (Shift $shift): int => self::minutes($shift), $planned));
            $given = BigDecimal::of($absence->hours)->multipliedBy(60);
            $partial = $given->isPositive() && $given->isLessThan($minutes);
            $hours = $partial ? $absence->hours : (string) BigDecimal::of($minutes)->dividedBy(60, 2, RoundingMode::HalfUp);
            [$ore, $parts] = $withOb ? $this->ob($context, $agreement, $planned) : [0, []];
            $ore = $partial && $minutes > 0 ? $given->multipliedBy($ore)->dividedBy($minutes, 0, RoundingMode::HalfUp)->toInt() : $ore;
            $absences[] = new AbsenceInput($absence->type, $absence->date, $hours, $ore, [...$parts, 'scheduled_minutes' => $minutes, 'partial' => $partial], $absence->extentBp);
        }

        $history = $this->history($context, $agreement, $employment->employee_id, $periodStart, $withOb);
        $schedule = $this->scheduleAverages($context, $agreement, $shifts, $periodStart, $withOb);
        $average = $history ?? $schedule;

        if ($average !== null) {
            $facts = [
                ...$facts,
                'average_weekly_ob' => $withOb ? $average['ob'] : null,
                'average_weekly_hours' => $average['hours'],
                'explanation' => ['reference_period' => $average['period'], 'average_weekly_hours_source' => $average['source']],
            ];
        }

        $facts['month_working_hours'] = $this->monthHours($context, $shifts);

        return $this->applyTerms($context, $agreement, $facts, $absences);
    }

    /**
     * The agreement's sick-pay terms for a payslip, from the agreement data
     * and the facts the run found (average week, the month's working hours,
     * variable pay). Pure; the golden payslips call it directly.
     *
     * @param  array{average_weekly_ob?: ?int, average_weekly_hours?: ?string, explanation?: array<string, mixed>, month_working_hours?: ?string, variable_monthly_ore?: ?int}  $facts
     * @param  ?list<AbsenceInput>  $absences  the absences with lost OB, when the schedule is known
     */
    public function applyTerms(PayrollContext $context, AgreementContext $agreement, array $facts = [], ?array $absences = null): PayrollContext
    {
        $sick = array_values(array_filter($context->absences, fn (AbsenceInput $absence): bool => $absence->type === 'SICK'));

        if ($sick === []) {
            return $context;
        }

        $periodStart = $this->periodStart($context, $sick);
        $rules = $this->rulesOn($agreement, $periodStart, $context);

        if ($rules === null) {
            return $context;
        }

        $version = $agreement->versionOn($periodStart) ?? $agreement->versionOn($context->periodEnd);
        $data = $rules->sickPay();
        $averageHours = $context->weeklyHours === null ? ($facts['average_weekly_hours'] ?? null) : null;
        $weeklyHours = $context->weeklyHours ?? $averageHours;

        $terms = new SickPayTerms(
            averageWeeklySupplementsOre: $facts['average_weekly_ob'] ?? null,
            averageWeeklyHours: $averageHours,
            longSick: $this->longSick($context, $agreement, $rules, $periodStart, $facts['variable_monthly_ore'] ?? null),
            explanation: array_filter([
                'agreement' => $rules->shortName(),
                'agreement_version_id' => $version['id'] ?? null,
                'source' => (string) ($data['source'] ?? ''),
                ...($facts['explanation'] ?? []),
            ]),
            hourValue: $hourValue = $this->hourValue($context, $data, $facts['month_working_hours'] ?? $this->patternMonthHours($context)),
            karens: $this->karens($context, $data, $weeklyHours),
            warnings: $this->dayPercentWarnings($context, $agreement, $hourValue),
        );

        return $context->withSickPay($absences ?? $context->absences, $terms);
    }

    /**
     * The first day of the sjuklöneperiod the run's first sick day belongs to.
     *
     * @param  non-empty-list<AbsenceInput>  $sick
     */
    private function periodStart(PayrollContext $context, array $sick): CarbonImmutable
    {
        $first = $sick[0]->date;

        foreach ($sick as $day) {
            $first = $day->date->lessThan($first) ? $day->date : $first;
        }

        $window = (int) ((array) $context->rules->get('sick_pay.reinfection_window_days'))['days'];

        return SickHistory::from($context->sickHistory, $window)->periodStartFor($first, $window);
    }

    private function rulesOn(AgreementContext $agreement, CarbonImmutable $periodStart, PayrollContext $context): ?AgreementRules
    {
        return ($agreement->versionOn($periodStart) ?? $agreement->versionOn($context->periodStart) ?? $agreement->versionOn($context->periodEnd))['rules'] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    private function hourValue(PayrollContext $context, array $data, ?string $monthHours): ?SickHourValue
    {
        $value = $data['hour_value'] ?? null;

        if (! is_array($value) || $context->salaryForm !== 'MONTHLY') {
            return null;
        }

        return new SickHourValue(
            method: (string) $value['method'],
            source: (string) ($value['source'] ?? ''),
            monthsPerYear: isset($value['months_per_year']) ? (string) $value['months_per_year'] : null,
            weeksPerYear: isset($value['weeks_per_year']) ? (string) $value['weeks_per_year'] : null,
            monthWorkingHours: $value['method'] === SickHourValue::MONTH_WORKING_HOURS ? $monthHours : null,
            dayBp: isset($value['day_bp']) ? (int) $value['day_bp'] : null,
            hourDivisor: isset($value['hour_divisor']) ? (string) $value['hour_divisor'] : null,
        );
    }

    /**
     * What a deduction per day (Detaljhandeln § 13.1) says that the data
     * cannot express yet: the fixed supplements in the day's base, and
     * another divisor when the month has more than 22 scheduled days.
     *
     * TODO(legal-verify): Detaljhandelsavtalet § 13.1–13.2 s. 42–43.
     *
     * @return list<string>
     */
    private function dayPercentWarnings(PayrollContext $context, AgreementContext $agreement, ?SickHourValue $value): array
    {
        if ($value?->method !== SickHourValue::DAY_PERCENT) {
            return [];
        }

        $warnings = [];

        if (($agreement->fixedSupplementsOre ?? 0) > 0) {
            $warnings[] = __('Avtalet räknar sjukavdraget per dag på månadslönen och de fasta tilläggen. De fasta tilläggen är inte med i avdraget, så kontrollera det.');
        }

        if ($context->weekPattern !== null) {
            $days = 0;

            for ($day = $context->periodStart; $day->lessThanOrEqualTo($context->periodEnd); $day = $day->addDay()) {
                $days += ContextBuilder::scheduled($day, $context->weekPattern) ? 1 : 0;
            }

            if ($days > self::DAY_PERCENT_MAX_DAYS) {
                $warnings[] = __('Månaden har :days schemalagda dagar. Avtalet delar då månadslönen med antalet schemalagda dagar i stället för att dra en fast procent per dag, så kontrollera sjukavdraget.', ['days' => $days]);
            }
        }

        return $warnings;
    }

    /** @param  array<string, mixed>  $data */
    private function karens(PayrollContext $context, array $data, ?string $weeklyHours): ?KarensTerms
    {
        $karens = $data['karens'] ?? null;

        if (! is_array($karens) || ($karens['unit'] ?? null) !== 'HOURS') {
            return null;
        }

        $source = (string) ($karens['weekly_hours'] ?? KarensTerms::AVERAGE);
        // The weekly hours agreed in advance are the employment's own, never an average of the schedule.
        $hours = $source === KarensTerms::AGREED ? $context->weeklyHours : $weeklyHours;

        if ($hours === null || BigDecimal::of($hours)->isZero()) {
            throw new PayrollBlockedException($source === KarensTerms::AGREED
                ? __('Avtalet räknar karensavdraget på den veckoarbetstid som är överenskommen i förväg, men anställningen saknar veckoarbetstid.')
                : __('Veckoarbetstid saknas på anställningen, och det finns varken attesterad tid före sjukperioden eller publicerade pass att räkna en genomsnittsvecka på. Den behövs för karensavdraget.'));
        }

        return new KarensTerms((int) $karens['percent_of_weekly_hours_bp'], $hours, $source, (string) ($karens['source'] ?? ''));
    }

    private function longSick(PayrollContext $context, AgreementContext $agreement, AgreementRules $rules, CarbonImmutable $periodStart, ?int $variableMonthly): ?LongSickTerms
    {
        $after = $rules->sickPay()['after_employer_period'] ?? null;

        if (! is_array($after) || $context->salaryForm !== 'MONTHLY') {
            return null;
        }

        $deduction = (array) ($after['deduction'] ?? []);
        // The duration group on the first day of the sick period, which may lie in an earlier month.
        $months = (int) $agreement->employmentStart->diffInMonths($periodStart);
        $chosen = null;

        foreach ((array) ($after['duration'] ?? []) as $duration) {
            $duration = (array) $duration;
            $byTime = $months >= (int) ($duration['min_employment_months'] ?? 0);
            $byTransfer = ($duration['or_direct_transfer'] ?? false) === true && $agreement->sickPayDirectTransfer;

            if ($byTime || $byTransfer) {
                $chosen = $duration;

                break;
            }
        }

        if ($chosen === null) {
            return null;
        }

        $pbb = (int) ((array) $context->rules->get('prisbasbelopp'))['ore'];
        $threshold = BigDecimal::of($pbb)->multipliedBy((string) $deduction['threshold_prisbasbelopp'])->toScale(0, RoundingMode::HalfUp)->toInt();

        if ($threshold <= 0) {
            throw new PayrollBlockedException(__('Prisbasbeloppet saknas i lönereglerna, så avtalets sjukavdrag efter dag 14 kan inte räknas.'));
        }

        $includes = array_values(array_intersect(self::MONTHLY_SALARY_PARTS, array_map('strval', (array) ($after['monthly_salary_includes'] ?? []))));
        $capIncludes = array_map('strval', (array) ($after['cap_includes'] ?? []));
        $fixed = (int) ($agreement->fixedSupplementsOre ?? 0);
        $variable = in_array('VARIABLE_AVERAGE', $includes, true) ? (int) $variableMonthly : 0;
        $warnings = [];

        if (in_array('BENEFITS', $includes, true)) {
            // TODO(legal-verify): kost and bostad at Skatteverket's value in "månadslön" (VU § 23.3, HT § 10.3.6) are not read from the benefits yet.
            $warnings[] = __('Avtalet räknar kost- och bostadsförmån i månadslönen för sjukavdraget från dag :from. Förmånerna räknas inte in automatiskt än, så kontrollera avdraget.', ['from' => (int) $after['from_day']]);
        }

        return new LongSickTerms(
            fromDay: (int) $after['from_day'],
            toDay: (int) $chosen['to_day'],
            belowBp: (int) $deduction['below_bp'],
            aboveBp: (int) $deduction['above_bp'],
            thresholdYearlyOre: $threshold,
            source: (string) ($after['source'] ?? ''),
            daysPerYear: (int) $deduction['days_per_year'],
            maxDaysIn12Months: isset($chosen['max_days_12_months']) ? (int) $chosen['max_days_12_months'] : null,
            extraMonthlyOre: (in_array('FIXED_SUPPLEMENTS', $includes, true) ? $fixed : 0) + $variable,
            capExtraMonthlyOre: in_array('FIXED_SUPPLEMENTS', $capIncludes, true) ? $fixed : 0,
            basisExplanation: array_filter([
                'group_to_day' => (int) $chosen['to_day'],
                'direct_transfer' => $agreement->sickPayDirectTransfer ? true : null,
                'fixed_supplements_ore' => $fixed > 0 ? $fixed : null,
                'variable_monthly_ore' => $variable > 0 ? $variable : null,
            ], fn (mixed $value): bool => $value !== null),
            warnings: $warnings,
        );
    }

    /**
     * The monthly average of the agreement's variable pay codes on approved
     * payslips in the twelve months before the sick period's month, over the
     * months the person was employed in them (at least one).
     */
    private function variableAverage(PayrollContext $context, int $employeeId, AgreementRules $rules, CarbonImmutable $periodStart): ?int
    {
        $after = (array) ($rules->sickPay()['after_employer_period'] ?? []);
        $codes = array_map('strval', (array) ($after['variable_pay_codes'] ?? []));

        if ($codes === [] || ! in_array('VARIABLE_AVERAGE', (array) ($after['monthly_salary_includes'] ?? []), true)) {
            return null;
        }

        $to = $periodStart->startOfMonth();
        $from = $to->subMonths(12)->max($context->employmentStart->startOfMonth());
        $months = max(1, (int) $from->diffInMonths($to));
        $sum = 0;

        foreach ($this->variable[$employeeId] ?? [] as $row) {
            if (in_array($row['code'], $codes, true) && $row['period'] >= $from->format('Y-m') && $row['period'] < $to->format('Y-m')) {
                $sum += $row['ore'];
            }
        }

        return intdiv($sum + intdiv($months, 2), $months);
    }

    /**
     * The working hours of the run's calendar month by the published shifts,
     * or by the employment's weekly pattern; null when neither is known.
     *
     * @param  list<Shift>  $shifts
     */
    private function monthHours(PayrollContext $context, array $shifts): ?string
    {
        $from = CarbonImmutable::parse($context->periodStart->toDateString(), ObSplitter::TIMEZONE);
        $to = CarbonImmutable::parse($context->periodEnd->toDateString(), ObSplitter::TIMEZONE)->addDay();
        $inMonth = array_filter($shifts, fn (Shift $shift): bool => $shift->starts_at->greaterThanOrEqualTo($from) && $shift->starts_at->lessThan($to));

        if ($inMonth !== []) {
            return (string) BigDecimal::of(array_sum(array_map(fn (Shift $shift): int => self::minutes($shift), $inMonth)))->dividedBy(60, 2, RoundingMode::HalfUp);
        }

        return $this->patternMonthHours($context);
    }

    /** The month's working hours by the weekly pattern, public holidays left out. */
    private function patternMonthHours(PayrollContext $context): ?string
    {
        if ($context->weekPattern === null) {
            return null;
        }

        $hours = BigDecimal::zero();

        for ($day = $context->periodStart; $day->lessThanOrEqualTo($context->periodEnd); $day = $day->addDay()) {
            if (ContextBuilder::scheduled($day, $context->weekPattern)) {
                $hours = $hours->plus($context->weekPattern[$day->dayOfWeekIso - 1] ?? '0');
            }
        }

        return $hours->isZero() ? null : (string) $hours;
    }

    /**
     * The average week from the attested time before the sick period: up to
     * HISTORY_WEEKS calendar weeks before the week of its first day, only
     * weeks the person was employed all week and not weeks with nothing but
     * absence. Null when no week has attested time.
     *
     * @return ?array{hours: string, ob: ?int, period: array{from: string, to: string, weeks: int}, source: string}
     */
    private function history(PayrollContext $context, AgreementContext $agreement, int $employeeId, CarbonImmutable $periodStart, bool $withOb): ?array
    {
        $monday = CarbonImmutable::parse($periodStart->toDateString())->startOfWeek(CarbonImmutable::MONDAY);
        $from = $monday->subWeeks(self::HISTORY_WEEKS);
        $weeks = [];

        for ($week = $from; $week->lessThan($monday); $week = $week->addWeek()) {
            if ($week->lessThan($context->employmentStart)) {
                continue;
            }

            $weeks[$week->toDateString()] = ['minutes' => 0, 'ob' => 0, 'attested' => false, 'absent' => false];
        }

        foreach ($this->attested[$employeeId] ?? [] as $row) {
            $key = $row->date->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

            if (! isset($weeks[$key])) {
                continue;
            }

            $weeks[$key]['minutes'] += $row->actual_minutes;
            $weeks[$key]['attested'] = true;
            $weeks[$key]['ob'] += $withOb ? $this->attestedOb($context, $agreement, $row) : 0;
        }

        foreach (array_keys($this->absent[$employeeId] ?? []) as $day) {
            $key = CarbonImmutable::parse($day)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

            if (isset($weeks[$key])) {
                $weeks[$key]['absent'] = true;
            }
        }

        // Before the first week with attested time there is no record of the time worked, which is not the same as none.
        $firstAttested = array_search(true, array_column($weeks, 'attested'), true);

        if ($firstAttested === false) {
            return null;
        }

        $weeks = array_slice($weeks, (int) $firstAttested, preserve_keys: true);
        $counted = array_filter($weeks, fn (array $week): bool => $week['attested'] || ! $week['absent']);
        $count = count($counted);

        if ($count === 0) {
            return null;
        }

        $minutes = array_sum(array_column($counted, 'minutes'));
        $ob = array_sum(array_column($counted, 'ob'));
        $first = (string) array_key_first($counted);

        return [
            'hours' => (string) BigDecimal::of($minutes)->dividedBy(60 * $count, 2, RoundingMode::HalfUp),
            'ob' => $withOb ? intdiv($ob + intdiv($count, 2), $count) : null,
            'period' => ['from' => $first, 'to' => $monday->subDay()->toDateString(), 'weeks' => $count],
            'source' => 'HISTORY',
        ];
    }

    /** The OB of an attested day, priced by the agreement version in force that day. */
    private function attestedOb(PayrollContext $context, AgreementContext $agreement, Attestation $row): int
    {
        $rules = $agreement->versionOn($row->date)['rules'] ?? null;

        if (! $rules instanceof AgreementRules) {
            return 0;
        }

        $total = 0;

        foreach ((array) ($row->details['ob'] ?? []) as $code => $minutes) {
            $rate = $this->pricer->hourlyOre($context, $rules, (string) $code, $row->date);

            if ($rate === null || (int) $minutes <= 0) {
                continue;
            }

            $total += BigDecimal::of($rate)->multipliedBy((int) $minutes)->dividedBy(60, 0, RoundingMode::HalfUp)->toInt();
        }

        return $total;
    }

    /**
     * The average weekly OB and hours of the published schedule from the
     * week of the sick period's first day, over the weeks in the reference
     * period that have published shifts; null when none has.
     *
     * @param  list<Shift>  $shifts
     * @return ?array{hours: string, ob: ?int, period: array{from: string, to: string, weeks: int}, source: string}
     */
    private function scheduleAverages(PayrollContext $context, AgreementContext $agreement, array $shifts, CarbonImmutable $periodStart, bool $withOb): ?array
    {
        $from = CarbonImmutable::parse($periodStart->toDateString(), ObSplitter::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
        $to = $from->addWeeks($this->referenceWeeks);
        $inWindow = array_values(array_filter($shifts, fn (Shift $shift): bool => $shift->starts_at->greaterThanOrEqualTo($from) && $shift->starts_at->lessThan($to)));

        if ($inWindow === []) {
            return null;
        }

        $weeks = count(array_unique(array_map(fn (Shift $shift): string => $shift->starts_at->setTimezone(ObSplitter::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $inWindow)));
        $minutes = array_sum(array_map(fn (Shift $shift): int => self::minutes($shift), $inWindow));
        $ob = $withOb ? $this->ob($context, $agreement, $inWindow)[0] : 0;

        return [
            'hours' => (string) BigDecimal::of($minutes)->dividedBy(60 * $weeks, 2, RoundingMode::HalfUp),
            'ob' => $withOb ? intdiv($ob + intdiv($weeks, 2), $weeks) : null,
            'period' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(), 'weeks' => $weeks],
            'source' => 'SCHEDULE',
        ];
    }

    /** Reads the attested days and absences before an old sick period for one employee. */
    private function loadHistory(int $employeeId, CarbonImmutable $periodStart): void
    {
        $from = $periodStart->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(self::HISTORY_WEEKS);

        $this->attested[$employeeId] = [
            ...Attestation::query()->where('employee_id', $employeeId)->whereIn('status', [Attestation::ATTESTED, Attestation::SENT])
                ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<', ($this->historyFrom ?? $periodStart)->toDateString())
                ->orderBy('date')->get(['id', 'employee_id', 'date', 'actual_minutes', 'details'])->all(),
            ...($this->attested[$employeeId] ?? []),
        ];

        foreach (Absence::query()->where('employee_id', $employeeId)->where('status', 'APPROVED')
            ->whereDate('date_from', '<=', $periodStart->subDay()->toDateString())->whereDate('date_to', '>=', $from->toDateString())
            ->get(['employee_id', 'date_from', 'date_to']) as $absence) {
            for ($day = $absence->date_from->max($from); $day->lessThan($periodStart) && $day->lessThanOrEqualTo($absence->date_to); $day = $day->addDay()) {
                $this->absent[$employeeId][$day->toDateString()] = true;
            }
        }

        $this->historyLoaded[$employeeId] = true;
    }

    private static function minutes(Shift $shift): int
    {
        return max(0, intdiv($shift->ends_at->getTimestamp() - $shift->starts_at->getTimestamp(), 60) - $shift->break_minutes);
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
}
