<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Run;

use App\Modules\Payroll\Domain\Engine\AbsenceInput;
use App\Modules\Payroll\Domain\Engine\PayInput;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Accumulator;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\WorkSchedule;
use App\Modules\Payroll\Support\Calendar\SwedishHolidays;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the calculation context for one employment in a run from the
 * stored data, so the engine itself never reads the database.
 */
final class ContextBuilder
{
    public function __construct(private readonly VacationLedger $vacation) {}

    /**
     * @param  Collection<int, PayrollInput>  $inputs  the employee's inputs in the run
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @param  array<string, mixed>  $settings
     */
    public function build(PayrollRun $run, Employee $employee, Employment $employment, Collection $inputs, Collection $payCodes, array $settings, RuleSet $rules): PayrollContext
    {
        $periodStart = $run->periodStart();
        $periodEnd = $run->periodEnd();
        $weekPattern = $this->weekPattern($employment);

        return new PayrollContext(
            birthDate: $employee->birth_date,
            taxResidence: $employee->tax_residence,
            taxTable: $employee->tax_table,
            taxColumn: $employee->tax_column,
            taxAdjustmentPercentBp: null,
            salaryForm: $employment->salary_form,
            monthlySalaryOre: $employment->monthly_salary_ore?->ore,
            hourlyRateOre: $employment->hourly_rate_ore?->ore,
            employmentRateBp: $employment->employment_rate_bp,
            employmentStart: $employment->start_date,
            employmentEnd: $employment->end_date,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            paymentDate: $run->payment_date,
            inputs: array_values($inputs->map(function (PayrollInput $input) use ($payCodes): PayInput {
                $code = $payCodes->get($input->pay_code_id) ?? throw new PayrollBlockedException(__('En löneart saknas.'));

                return new PayInput($code->code, $input->note ?: $code->name_sv, $code->category, $code->is_taxable, $code->tax_method, $code->is_contribution_basis, $code->is_vacation_basis,
                    $input->quantity === null ? null : (string) $input->quantity, $input->rate_ore?->ore, $input->amount_ore?->ore);
            })->all()),
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
            vacation: $this->vacation->input($employee, $employment, $periodStart, $settings, $rules, $weekPattern, $run->id),
        );
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
