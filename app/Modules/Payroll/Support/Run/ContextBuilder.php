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
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the calculation context for one employment in a run from the
 * stored data, so the engine itself never reads the database.
 */
final class ContextBuilder
{
    /**
     * @param  Collection<int, PayrollInput>  $inputs  the employee's inputs in the run
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @param  array<string, mixed>  $settings
     */
    public function build(PayrollRun $run, Employee $employee, Employment $employment, Collection $inputs, Collection $payCodes, array $settings, RuleSet $rules): PayrollContext
    {
        $periodStart = $run->periodStart();
        $periodEnd = $run->periodEnd();

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
            absences: $this->absenceDays($employee, $periodStart, $periodEnd),
            sickHistory: $this->sickHistory($employee, $periodStart),
        );
    }

    /**
     * Approved absence in the period, one entry per day.
     *
     * @return list<AbsenceInput>
     */
    private function absenceDays(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];

        foreach ($this->approvedAbsences($employee, $from, $to) as $absence) {
            if ($absence->hours === null) {
                throw new PayrollBlockedException(__('Frånvaron från :date saknar timmar per dag.', ['date' => $absence->date_from->toDateString()]));
            }

            for ($day = $absence->date_from->max($from); $day->lessThanOrEqualTo($absence->date_to->min($to)); $day = $day->addDay()) {
                $days[] = new AbsenceInput($absence->type, $day, (string) $absence->hours);
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
