<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Run;

use App\Modules\Payroll\Actions\ExpenseClaims;
use App\Modules\Payroll\Domain\Engine\AbsenceInput;
use App\Modules\Payroll\Domain\Engine\PayInput;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\Steps\Additions;
use App\Modules\Payroll\Domain\Engine\Steps\BaseSalary;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Accumulator;
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
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\Models\WorkSchedule;
use App\Modules\Payroll\Support\Calendar\SwedishHolidays;
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

    public function __construct(private readonly VacationLedger $vacation, private readonly PayrollExtensions $extensions) {}

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

        $context = new PayrollContext(
            birthDate: $employee->birth_date,
            taxResidence: $employee->tax_residence,
            taxTable: $employee->tax_table,
            taxColumn: $employee->tax_column,
            taxAdjustmentPercentBp: null,
            salaryForm: $employment->salary_form,
            monthlySalaryOre: $this->salaryAt($employment, $periodStart),
            hourlyRateOre: $employment->hourly_rate_ore?->ore,
            employmentRateBp: $employment->employment_rate_bp,
            employmentStart: $employment->start_date,
            employmentEnd: $employment->end_date,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            paymentDate: $run->payment_date,
            inputs: [...array_values($inputs->map(function (PayrollInput $input) use ($payCodes): PayInput {
                $code = $payCodes->get($input->pay_code_id) ?? throw new PayrollBlockedException(__('En löneart saknas.'));

                return new PayInput($code->code, $input->note ?: $code->name_sv, $code->category, $code->is_taxable, $code->tax_method, $code->is_contribution_basis, $code->is_vacation_basis,
                    $input->quantity === null ? null : (string) $input->quantity, $input->rate_ore?->ore, $input->amount_ore?->ore,
                    $code->isAgreementPriced() ? array_filter([Additions::AGREEMENT_PRICED => true, 'date_from' => $input->date_from?->toDateString(), 'date_to' => $input->date_to?->toDateString()]) : []);
            })->all()), ...$this->timeReports($timeReports ?? collect(), $payCodes), ...$this->lateTimeReports($lateTimeReports, $payCodes), ...$this->outlays($run, $employee), ...$this->retroactive($run, $employment, $payCodes)],
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
            sickHistory: $this->sickHistory($employee, $periodStart),
            weekPattern: $weekPattern,
            pension: $this->pension($employment),
            vacation: $this->vacation->input($employee, $employment, $periodStart, $settings, $rules, $weekPattern, $run->id),
        );

        $compTime = CompTimeEntry::query()
            ->where('employee_id', $employee->id)
            ->whereIn('kind', [CompTimeEntry::PAID_OUT, CompTimeEntry::TAKEN])
            ->whereDate('date', '>=', $periodStart->max($employment->start_date)->toDateString())
            ->whereDate('date', '<=', ($employment->end_date === null ? $periodEnd : $periodEnd->min($employment->end_date))->toDateString())
            ->get();

        if ($compTime->isNotEmpty()) {
            $code = $payCodes->first(fn (PayCode $code): bool => $code->code === CompTimeLedger::PAY_CODE);
            $context = $context->withInputs([...$context->inputs, ...CompTimeLedger::payInputs($compTime, $context, $code)]);
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
                $days[] = new AbsenceInput($absence->type, $day, self::scheduled($day, $weekPattern) ? (string) $absence->hours : '0');
            }
        }

        return $days;
    }

    /**
     * The last sick period before the run and how many new sick periods
     * started in the twelve months before it.
     *
     * TODO(legal-verify): the karensavdrag count here is the number of sick
     * spells that started in the twelve months before the period.
     *
     * @return array{last_sick_period_end?: string, last_sick_period_days?: int, karens_count_12m?: int}
     */
    private function sickHistory(Employee $employee, CarbonImmutable $periodStart): array
    {
        $sick = $this->approvedAbsences($employee, $periodStart->subYear(), $periodStart->subDay())
            ->where('type', 'SICK')
            ->sortBy('date_from')
            ->values();

        if ($sick->isEmpty()) {
            return [];
        }

        /** @var Absence $last */
        $last = $sick->last();

        return [
            'last_sick_period_end' => $last->date_to->min($periodStart->subDay())->toDateString(),
            'last_sick_period_days' => (int) $last->date_from->diffInDays($last->date_to->min($periodStart->subDay())) + 1,
            'karens_count_12m' => $sick->count(),
        ];
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

            foreach ($own->sortBy(fn (TimeReport $report): string => $report->date->toDateString()) as $report) {
                $date = $report->date->toDateString();
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
