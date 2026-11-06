<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Run;

use App\Modules\Payroll\Actions\ExpenseClaims;
use App\Modules\Payroll\Actions\SaveEmployee;
use App\Modules\Payroll\Domain\Engine\AbsenceInput;
use App\Modules\Payroll\Domain\Engine\PayInput;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\Steps\Additions;
use App\Modules\Payroll\Domain\Engine\Steps\BaseSalary;
use App\Modules\Payroll\Domain\Engine\Steps\NetPay;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Accumulator;
use App\Modules\Payroll\Models\Benefit;
use App\Modules\Payroll\Models\CompTimeEntry;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\PensionPlan;
use App\Modules\Payroll\Models\SalaryChange;
use App\Modules\Payroll\Models\SickPayPeriod;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\Models\WorkSchedule;
use App\Modules\Payroll\Support\Calendar\SwedishHolidays;
use App\Modules\Payroll\Support\CarBenefits;
use App\Modules\Payroll\Support\CompTimeLedger;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the calculation context for one employment in a run from the
 * stored data, so the engine itself never reads the database.
 */
final class ContextBuilder
{
    public const string RETRO_CODE = 'RETROLON';

    /** How far back the sick history is read: twelve months for the occasions and the agreement's days from day 15, plus the chain before them. */
    public const int SICK_HISTORY_MONTHS = 15;

    public function __construct(private readonly VacationLedger $vacation, private readonly PayrollExtensions $extensions, private readonly CarBenefits $cars) {}

    /**
     * @param  Collection<int, PayrollInput>  $inputs  the employee's inputs in the run
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @param  array<string, mixed>  $settings
     * @param  ?Collection<int, TimeReport>  $timeReports  the employment's approved hourly reports the run pays
     * @param  list<array{month: CarbonImmutable, rate_ore: ?int, reports: Collection<int, TimeReport>}>  $lateTimeReports  reports from earlier months the person running payroll chose to include (TimeReportPay::lateGroups)
     */
    public function build(PayrollRun $run, Employee $employee, Employment $employment, Collection $inputs, Collection $payCodes, array $settings, RuleSet $rules, ?Collection $timeReports = null, array $lateTimeReports = []): PayrollContext
    {
        $periodStart = $run->periodStart();
        $periodEnd = $run->periodEnd();
        $weekPattern = $this->weekPattern($employment);
        // Skatteverket's jämkning decision is followed for payments on the days it is in force (SFL 11 kap. 27 §).
        $adjustment = $employee->taxAdjustmentOn($run->payment_date);

        $entered = array_values($inputs->map(function (PayrollInput $input) use ($payCodes): PayInput {
            $code = $payCodes->get($input->pay_code_id) ?? throw new PayrollBlockedException(__('En löneart saknas.'));

            return new PayInput($code->code, $input->note ?: $code->name_sv, $code->category, $code->is_taxable, $code->tax_method, $code->is_contribution_basis, $code->is_vacation_basis,
                $input->quantity === null ? null : (string) $input->quantity, $input->rate_ore?->ore, $input->amount_ore?->ore,
                $code->isAgreementPriced() ? array_filter([Additions::AGREEMENT_PRICED => true, 'date_from' => $input->date_from?->toDateString(), 'date_to' => $input->date_to?->toDateString()]) : []);
        })->all());

        $context = new PayrollContext(
            birthDate: $employee->birth_date,
            taxResidence: $employee->tax_residence,
            taxTable: $employee->tax_table,
            taxColumn: $employee->tax_column,
            taxAdjustmentPercentBp: $adjustment === 'PERCENT' ? $employee->tax_adjustment_value : null,
            salaryForm: $employment->salary_form,
            monthlySalaryOre: $this->salaryAt($employment, $periodStart),
            hourlyRateOre: $employment->hourly_rate_ore?->ore,
            employmentRateBp: $employment->employment_rate_bp,
            employmentStart: $employment->start_date,
            employmentEnd: $employment->end_date,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            paymentDate: $run->payment_date,
            inputs: [...$entered, ...$this->timeReports($timeReports ?? collect(), $payCodes), ...$this->lateTimeReports($lateTimeReports, $payCodes), ...$this->outlays($run, $employee), ...$this->retroactive($run, $employment, $payCodes), ...$this->carBenefits($run, $employee, $employment, $payCodes, $entered)],
            accumulators: Accumulator::query()
                ->where('employee_id', $employee->id)
                ->where('scope', 'CALENDAR_YEAR')
                ->where('year', $run->payment_date->year)
                ->pluck('value', 'key')
                ->map(fn (mixed $value): int => (int) $value)
                ->all(),
            settings: $settings,
            rules: $rules,
            weeklyHours: $employment->weekly_hours === null ? null : (string) $employment->weekly_hours,
            absences: $this->absenceDays($employee, $periodStart, $periodEnd, $weekPattern),
            sickHistory: $this->sickHistory($employee, $run, $periodStart),
            weekPattern: $weekPattern,
            pension: $this->pension($employment),
            vacation: $this->vacation->input($employee, $employment, $periodStart, $settings, $rules, $weekPattern, $run->id),
            // A claim from deductions larger than the pay is owed until taken back, also across the turn of the year.
            employeeClaimOre: max(0, (int) Accumulator::query()
                ->where('employee_id', $employee->id)
                ->where('scope', 'CALENDAR_YEAR')
                ->where('key', NetPay::CLAIM_KEY)
                ->sum('value')),
            taxAdjustmentTable: $adjustment === 'TABLE' ? $employee->tax_adjustment_table : null,
            taxAdjustmentColumn: $adjustment === 'TABLE' ? $employee->tax_adjustment_column : null,
            taxAdjustmentAmountOre: $adjustment === 'AMOUNT' ? $employee->tax_adjustment_value : null,
            sideIncome: $employee->tax_residence === 'SE' && $employee->withholding_basis === SaveEmployee::WITHHOLDING_SIDE_INCOME,
        );

        $compTime = CompTimeEntry::query()
            ->where('employee_id', $employee->id)
            ->whereIn('kind', [CompTimeEntry::PAID_OUT, CompTimeEntry::TAKEN])
            ->whereDate('date', '>=', $periodStart->max($employment->start_date)->toDateString())
            ->whereDate('date', '<=', ($employment->end_date === null ? $periodEnd : $periodEnd->min($employment->end_date))->toDateString())
            ->get();

        if ($compTime->isNotEmpty()) {
            $code = $payCodes->first(fn (PayCode $code): bool => $code->code === CompTimeLedger::PAY_CODE);
            // The comp time paid follows the vacation flag of the overtime it was earned on (first in, first out).
            $history = CompTimeEntry::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', '<=', $periodEnd->toDateString())
                ->get();
            $origins = CompTimeLedger::origins($history, $payCodes->mapWithKeys(fn (PayCode $payCode): array => [$payCode->code => $payCode->is_vacation_basis])->all(), $code === null || $code->is_vacation_basis);
            $context = $context->withInputs([...$context->inputs, ...CompTimeLedger::payInputs($compTime, $context, $code, $origins)]);
        }

        // Other modules that are on add their part, such as the collective agreement (ADR-033).
        return $this->extensions->extend($context, $run, $employment);
    }

    /**
     * Approved absence in the period, one entry per calendar day. The hours
     * entered per day count only on days the employee is scheduled to work
     * and that are not public holidays; other days carry 0 hours, so a sick
     * spell still counts its calendar days.
     *
     * @param  ?list<string>  $weekPattern
     * @return list<AbsenceInput>
     */
    private function absenceDays(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, ?array $weekPattern): array
    {
        $days = [];

        foreach ($this->approvedAbsences($employee, $from, $to) as $absence) {
            if ($absence->hours === null) {
                throw new PayrollBlockedException(__('Frånvaron från :date saknar timmar per dag.', ['date' => $absence->date_from->toDateString()]));
            }

            for ($day = $absence->date_from->max($from); $day->lessThanOrEqualTo($absence->date_to->min($to)); $day = $day->addDay()) {
                $days[] = new AbsenceInput($absence->type, $day, self::scheduled($day, $weekPattern) ? (string) $absence->hours : '0', extentBp: (int) ($absence->extent_bp ?? 10000));
            }
        }

        return $days;
    }

    /**
     * The sick history before the run, for the SickPay step (SickHistory):
     * the approved sick spells of the last fifteen months (the step links
     * them into sjuklöneperioder), and from approved payroll runs what each
     * sjuklöneperiod's karensavdrag was and how much of it has been
     * deducted, and the days paid under an agreement's deduction from day
     * 15. Two queries.
     *
     * @return array{spells?: list<array{from: string, to: string}>, periods?: array<string, array{full: int, deducted: int}>, long_sick_dates?: list<string>}
     */
    private function sickHistory(Employee $employee, PayrollRun $run, CarbonImmutable $periodStart): array
    {
        $from = $periodStart->subMonths(self::SICK_HISTORY_MONTHS);
        $before = $periodStart->subDay();
        $spells = array_values($this->approvedAbsences($employee, $from, $before)
            ->where('type', 'SICK')
            ->map(fn (Absence $absence): array => ['from' => $absence->date_from->max($from)->toDateString(), 'to' => $absence->date_to->min($before)->toDateString()])
            ->sortBy('from')
            ->all());
        $rows = SickPayPeriod::query()
            ->where('sick_pay_periods.employee_id', $employee->id)
            ->where('sick_pay_periods.payroll_run_id', '!=', $run->id)
            ->join('payroll_runs', 'payroll_runs.id', '=', 'sick_pay_periods.payroll_run_id')
            ->whereNotIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->whereDate('sick_pay_periods.period_start', '>=', $from->subMonths(self::SICK_HISTORY_MONTHS)->toDateString())
            ->whereDate('sick_pay_periods.last_day', '<', $periodStart->toDateString())
            ->get(['sick_pay_periods.period_start', 'sick_pay_periods.karens_full_ore', 'sick_pay_periods.karens_deducted_ore', 'sick_pay_periods.long_sick_dates']);

        if ($spells === [] && $rows->isEmpty()) {
            return [];
        }

        $periods = [];
        $long = [];

        foreach ($rows as $row) {
            $key = $row->period_start->toDateString();
            $periods[$key] = [
                'full' => max($periods[$key]['full'] ?? 0, $row->karens_full_ore),
                'deducted' => ($periods[$key]['deducted'] ?? 0) + $row->karens_deducted_ore,
            ];
            $long = [...$long, ...array_map('strval', $row->long_sick_dates)];
        }

        return ['spells' => $spells, 'periods' => $periods, 'long_sick_dates' => array_values(array_unique($long))];
    }

    /**
     * The first week of the employment's schedule, or the company's first
     * schedule (created when payroll is turned on). Rotating schedules use
     * the first week only. TODO: rotating weeks.
     *
     * @return ?list<string>
     */
    private function weekPattern(Employment $employment): ?array
    {
        $schedule = $employment->work_schedule_id === null
            ? WorkSchedule::query()->orderBy('id')->first()
            : WorkSchedule::query()->find($employment->work_schedule_id);
        $week = $schedule?->pattern['weeks'][0] ?? null;

        return is_array($week) ? array_map('strval', $week) : null;
    }

    /**
     * Approved time reports as one hourly pay line: the hours of the period
     * at the employment's hourly rate (the Additions step prices it), taxed
     * and earning vacation pay as the pay code says. Reports on supplement
     * pay codes (OB, overtime, from Planering) follow as one line per pay
     * code (supplementReports).
     *
     * @param  Collection<int, TimeReport>  $reports
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @return list<PayInput>
     */
    private function timeReports(Collection $reports, Collection $payCodes): array
    {
        $hourlyId = $payCodes->first(fn (PayCode $code): bool => $code->code === TimeReportPay::PAY_CODE)?->id;
        $hourly = fn (TimeReport $report): bool => $hourlyId === null || $report->pay_code_id === $hourlyId;

        return [...$this->hourlyReports($reports->filter($hourly)->values(), $payCodes), ...$this->supplementReports($reports->reject($hourly)->values(), $payCodes)];
    }

    /**
     * Approved reports on supplement pay codes (TimeReportPay::supplementCodes),
     * one line per pay code with the hours of the period. A pay code the
     * collective agreement prices is priced by it per work date (the hours
     * per date go along, so a new rate step mid-period is respected);
     * otherwise the Additions step takes the hourly rate, or blocks.
     *
     * @param  Collection<int, TimeReport>  $reports
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @return list<PayInput>
     */
    private function supplementReports(Collection $reports, Collection $payCodes): array
    {
        $inputs = [];

        foreach ($reports->groupBy('pay_code_id') as $codeId => $own) {
            $code = $payCodes->get($codeId) ?? throw new PayrollBlockedException(__('En löneart saknas.'));
            $byDate = [];

            // A report can be priced on another day than it is paid with (priced_on: overtime of a period sent with its last day, ADR-033 H.1).
            foreach ($own->sortBy(fn (TimeReport $report): string => ($report->priced_on ?? $report->date)->toDateString()) as $report) {
                $date = ($report->priced_on ?? $report->date)->toDateString();
                $byDate[$date] = (string) BigDecimal::of($byDate[$date] ?? '0')->plus((string) $report->hours);
            }

            $inputs[] = new PayInput(
                $code->code,
                __(':name enligt tidrapport', ['name' => $code->name_sv]),
                $code->category,
                $code->is_taxable,
                $code->tax_method,
                $code->is_contribution_basis,
                $code->is_vacation_basis,
                quantity: (string) TimeReportPay::hours($own),
                explanation: [
                    'source' => 'time_report',
                    'days' => count($byDate),
                    'time_report_ids' => $own->pluck('id')->values()->all(),
                    ...($code->isAgreementPriced() ? [
                        Additions::AGREEMENT_PRICED => true,
                        'date_from' => array_key_first($byDate),
                        'date_to' => array_key_last($byDate),
                        'hours_by_date' => $byDate,
                    ] : []),
                ],
            );
        }

        return $inputs;
    }

    /**
     * @param  Collection<int, TimeReport>  $reports  the hourly pay code's reports
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @return list<PayInput>
     */
    private function hourlyReports(Collection $reports, Collection $payCodes): array
    {
        if ($reports->isEmpty()) {
            return [];
        }

        $code = $payCodes->first(fn (PayCode $code): bool => $code->code === TimeReportPay::PAY_CODE)
            ?? throw new PayrollBlockedException(__('Lönearten Timlön saknas. Slå av och på lönemodulen för att lägga till systemets lönearter.'));

        return [new PayInput(
            $code->code,
            __('Timlön enligt tidrapport'),
            $code->category,
            $code->is_taxable,
            $code->tax_method,
            $code->is_contribution_basis,
            $code->is_vacation_basis,
            quantity: (string) TimeReportPay::hours($reports),
            explanation: ['source' => 'time_report', 'days' => $reports->count(), 'time_report_ids' => $reports->pluck('id')->values()->all()],
        )];
    }

    /**
     * Approved time reports from earlier months that the person running
     * payroll chose to pay in this run: one hourly pay line per month and
     * employment, saying which month the hours belong to, at the hourly rate
     * of the employment the hours were worked in. The pay code decides tax,
     * contributions and vacation basis as for the run's own month.
     *
     * @param  list<array{month: CarbonImmutable, rate_ore: ?int, reports: Collection<int, TimeReport>}>  $groups
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @return list<PayInput>
     */
    private function lateTimeReports(array $groups, Collection $payCodes): array
    {
        if ($groups === []) {
            return [];
        }

        $code = $payCodes->first(fn (PayCode $code): bool => $code->code === TimeReportPay::PAY_CODE)
            ?? throw new PayrollBlockedException(__('Lönearten Timlön saknas. Slå av och på lönemodulen för att lägga till systemets lönearter.'));

        return array_map(function (array $group) use ($code): PayInput {
            $month = $group['month']->translatedFormat('F Y');

            if ($group['rate_ore'] === null) {
                throw new PayrollBlockedException(__('Timlönen saknas för anställningen som timmarna i :month hör till.', ['month' => $month]));
            }

            return new PayInput(
                $code->code,
                __('Timlön enligt tidrapport (:month)', ['month' => $month]),
                $code->category,
                $code->is_taxable,
                $code->tax_method,
                $code->is_contribution_basis,
                $code->is_vacation_basis,
                quantity: (string) TimeReportPay::hours($group['reports']),
                rateOre: $group['rate_ore'],
                explanation: ['source' => 'time_report', 'month' => $group['month']->format('Y-m'), 'days' => $group['reports']->count(), 'time_report_ids' => $group['reports']->pluck('id')->values()->all()],
            );
        }, $groups);
    }

    /**
     * Approved outlays not yet repaid: they are claimed by this run, so the
     * same outlay is never repaid twice, and repaid as net additions.
     *
     * @return list<PayInput>
     */
    private function outlays(PayrollRun $run, Employee $employee): array
    {
        $expenses = Expense::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'APPROVED')
            ->where(fn ($query) => $query->whereNull('payroll_run_id')->orWhere('payroll_run_id', $run->id))
            ->orderBy('date')
            ->get();

        return array_values($expenses->map(function (Expense $expense) use ($run): PayInput {
            if ($expense->payroll_run_id === null) {
                $expense->forceFill(['payroll_run_id' => $run->id])->save();
            }

            return new PayInput('UTLAGG', ExpenseClaims::label($expense), 'EXPENSE', false, 'NONE', false, false, amountOre: $expense->amount_ore->ore);
        })->all());
    }

    /** @var array{run: int, cars: Collection<int|string, Collection<int, Benefit>>, carriers: array<int, int>, rules: RuleSet}|null the run's cars, loaded once by prepareCars() */
    private ?array $preparedCars = null;

    /**
     * Loads the run's company cars once (Lön → Förmånsbilar, ADR-061): every
     * car by employee, which employment carries them (the one that started
     * last, when an employee has two in the month), and the rules for the
     * month's calendar year — the run's own when the payment day is in the
     * same year.
     *
     * @param  Collection<int, Employment>  $employments  the employments in the run
     */
    public function prepareCars(PayrollRun $run, Collection $employments, RuleSet $rules): void
    {
        $periodStart = $run->periodStart();
        $carriers = [];

        foreach ($employments->sortBy(fn (Employment $employment): string => $employment->start_date->toDateString().'#'.str_pad((string) $employment->id, 12, '0', STR_PAD_LEFT)) as $employment) {
            $carriers[$employment->employee_id] = $employment->id;
        }

        $this->preparedCars = [
            'run' => $run->id,
            'cars' => $run->type === 'REGULAR' ? Benefit::query()->where('type', Benefit::CAR)->whereIn('employee_id', array_keys($carriers))->get()->groupBy('employee_id') : collect(),
            'carriers' => $carriers,
            'rules' => $rules->date->year === $periodStart->year ? $rules : RuleSet::for($periodStart),
        ];
    }

    /**
     * A fingerprint of the car rules the run used when they are not the
     * payment day's (December paid in January), for the run's rule snapshot.
     */
    public function carRulesHash(RuleSet $rules): string
    {
        $carRules = $this->preparedCars['rules'] ?? $rules;

        return $carRules === $rules ? '' : $carRules->snapshotHash();
    }

    /**
     * The registered company cars for the month, only in the regular run and
     * on the carrying employment's payslip.
     *
     * @param  Collection<int, PayCode>  $payCodes
     * @param  list<PayInput>  $entered
     * @return list<PayInput>
     */
    private function carBenefits(PayrollRun $run, Employee $employee, Employment $employment, Collection $payCodes, array $entered): array
    {
        if ($run->type !== 'REGULAR') {
            return [];
        }

        if ($this->preparedCars === null || $this->preparedCars['run'] !== $run->id) {
            $this->prepareCars($run, Employment::query()
                ->where('employee_id', $employee->id)
                ->whereDate('start_date', '<=', $run->periodEnd()->toDateString())
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $run->periodStart()->toDateString()))
                ->get(), RuleSet::for($run->periodStart()));
        }

        /** @var array{run: int, cars: Collection<int|string, Collection<int, Benefit>>, carriers: array<int, int>, rules: RuleSet} $prepared */
        $prepared = $this->preparedCars;
        $cars = $prepared['cars']->get($employee->id);

        if ($cars === null || ($prepared['carriers'][$employee->id] ?? null) !== $employment->id) {
            return [];
        }

        return $this->cars->payInputs($cars, $run->periodStart(), $payCodes, $entered, $prepared['rules']);
    }

    /**
     * The monthly salary in force on the first day of a period: the latest
     * salary change from that day or earlier, or the employment's salary when
     * there is no history.
     */
    public static function salaryAt(Employment $employment, CarbonImmutable $day): ?int
    {
        $change = SalaryChange::query()
            ->where('employment_id', $employment->id)
            ->whereDate('valid_from', '<=', $day->toDateString())
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();

        return $change->monthly_salary_ore->ore ?? $employment->monthly_salary_ore?->ore;
    }

    /**
     * Retroactive pay (PAYROLL_MODULE.md B.14): for each earlier approved
     * month from the first salary change on, the monthly salary line as it
     * should have been at the salary now in force for that month, less what
     * was paid and any retroactive pay already paid for the month. Derived
     * from the data each time, so it is never paid twice.
     *
     * TODO(legal-verify): absence deductions and vacation pay in those months
     * were valued on the old salary and are not recalculated.
     *
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @return list<PayInput>
     */
    private function retroactive(PayrollRun $run, Employment $employment, Collection $payCodes): array
    {
        // The first entry is the salary the history started from; months before the first real change are not revisited.
        $from = SalaryChange::query()->where('employment_id', $employment->id)->orderBy('valid_from')->orderBy('id')->skip(1)->first()?->valid_from->format('Y-m');

        if ($from === null) {
            return [];
        }

        $payslips = Payslip::query()
            ->with('lines')
            ->where('employment_id', $employment->id)
            ->whereIn('payroll_run_id', PayrollRun::query()->whereIn('status', [PayrollRun::APPROVED, PayrollRun::PAID, PayrollRun::POSTED])->whereKeyNot($run->id)->select('id'))
            ->get();
        $runs = PayrollRun::query()->whereIn('id', $payslips->pluck('payroll_run_id'))->pluck('period', 'id');
        $codes = $payCodes->pluck('code', 'id');
        $owed = [];

        foreach ($payslips as $payslip) {
            $period = (string) $runs->get($payslip->payroll_run_id);

            foreach ($payslip->lines as $line) {
                /** @var PayslipLine $line */
                $code = $codes->get($line->pay_code_id);
                $month = $code === self::RETRO_CODE ? (string) ($line->explanation['retro_period'] ?? '') : $period;

                if ($month < $from || $month >= $run->period) {
                    continue;
                }

                if ($code === self::RETRO_CODE) {
                    $owed[$month] = ($owed[$month] ?? 0) - $line->amount_ore->ore;

                    continue;
                }

                $used = (int) ($line->explanation['monthly_salary_ore'] ?? 0);

                if ($code !== BaseSalary::CODE || $used <= 0) {
                    continue;
                }

                $expected = (int) self::salaryAt($employment, CarbonImmutable::parse("{$month}-01"));
                $owed[$month] = ($owed[$month] ?? 0) + BigDecimal::of($line->amount_ore->ore)->multipliedBy($expected - $used)->dividedBy($used, 0, RoundingMode::HalfUp)->toInt();
            }
        }

        $owed = array_filter($owed);

        if ($owed === []) {
            return [];
        }

        $payCode = $payCodes->first(fn (PayCode $code): bool => $code->code === self::RETRO_CODE)
            ?? throw new PayrollBlockedException(__('Lönearten Retroaktiv lön saknas. Spara den nya lönen igen under Lön → Anställda, så läggs lönearten till.'));
        ksort($owed);

        return array_map(fn (string $month, int $amount): PayInput => new PayInput(
            self::RETRO_CODE,
            __('Retroaktiv lön för :period', ['period' => $month]),
            $payCode->category,
            $payCode->is_taxable,
            $payCode->tax_method,
            $payCode->is_contribution_basis,
            $payCode->is_vacation_basis,
            amountOre: $amount,
            explanation: ['retro_period' => $month],
        ), array_map('strval', array_keys($owed)), array_values($owed));
    }

    /**
     * The employment's pension plan as rates on pay below and above the
     * threshold, or null without a plan.
     *
     * @return ?array{below_bp: int, above_bp: int, name: string}
     */
    private function pension(Employment $employment): ?array
    {
        $plan = $employment->pension_plan_id === null ? null : PensionPlan::query()->find($employment->pension_plan_id);

        if (! $plan instanceof PensionPlan) {
            return null;
        }

        return ['below_bp' => (int) ($plan->rules['below_bp'] ?? 0), 'above_bp' => (int) ($plan->rules['above_bp'] ?? 0), 'name' => $plan->name];
    }

    /**
     * A working day by the schedule, or Monday to Friday when there is none.
     * Public holidays are never working days; an ordinary Sunday is one when
     * the schedule has hours on Sundays.
     *
     * @param  ?list<string>  $weekPattern
     */
    public static function scheduled(CarbonImmutable $day, ?array $weekPattern): bool
    {
        if (in_array($day->toDateString(), SwedishHolidays::forYear($day->year), true)) {
            return false;
        }

        return $weekPattern === null
            ? ! $day->isWeekend()
            : (float) ($weekPattern[$day->dayOfWeekIso - 1] ?? '0') > 0;
    }

    /** @return Collection<int, Absence> */
    private function approvedAbsences(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Absence::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'APPROVED')
            ->whereDate('date_from', '<=', $to->toDateString())
            ->whereDate('date_to', '>=', $from->toDateString())
            ->get();
    }
}
