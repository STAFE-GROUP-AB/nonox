<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Vacation;

use App\Modules\Payroll\Domain\Engine\VacationInput;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\WorkSchedule;
use App\Modules\Payroll\Support\Calendar\SwedishHolidays;
use App\Modules\Payroll\Support\Run\ContextBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Each employee's vacation, derived from the stored employments, absences
 * and approved payslips (semesterlagen 3, 7, 16 b §§):
 *
 * - The semesterår starts in the company's vacation-year month (1 April by
 *   law); the intjänandeår is the year before it.
 * - Paid days = days per year × (employed days − days of non-qualifying
 *   absence) ÷ days in the intjänandeår, rounded up (7 §). Unpaid leave counts
 *   in full; sickness, parental leave, VAB and military service only beyond
 *   the days the rule `vacation.qualifying_absence_days` allows (17–17 b §§).
 * - Days taken are the semesterdagar of approved vacation absence in the
 *   semesterår before the date.
 * - The procentregeln basis is the vacation-pay basis of approved payslips
 *   paid in the intjänandeår.
 *
 * TODO: saved days (18 §) and the yearly roll-over (26 a §) are not yet kept.
 */
final class VacationLedger
{
    /** Absence that never earns vacation (7 §): full unpaid leave. */
    private const array NEVER_QUALIFYING = ['LEAVE_UNPAID', 'STUDY', 'OTHER'];

    /**
     * The semesterår that contains the day, and the intjänandeår before it.
     *
     * @return array{year: array{CarbonImmutable, CarbonImmutable}, earning: array{CarbonImmutable, CarbonImmutable}}
     */
    public static function years(CarbonImmutable $day, int $startMonth): array
    {
        $start = CarbonImmutable::create($day->month >= $startMonth ? $day->year : $day->year - 1, $startMonth, 1) ?? $day;

        return [
            'year' => [$start, $start->addYear()->subDay()],
            'earning' => [$start->subYear(), $start->subDay()],
        ];
    }

    /**
     * The vacation input for one payslip.
     *
     * @param  array<string, mixed>  $settings
     * @param  ?list<string>  $weekPattern
     */
    public function input(Employee $employee, Employment $employment, CarbonImmutable $periodStart, array $settings, RuleSet $rules, ?array $weekPattern): VacationInput
    {
        $balance = $this->balances(collect([$employee]), collect([$employee->id => $employment]), $periodStart, $settings, $rules, [$employee->id => $weekPattern])[$employee->id];

        return new VacationInput(
            rule: $employment->vacation_rule ?? (string) ($settings['vacation_rule_default'] ?? 'SAMMALONEREGELN'),
            paidDaysEarned: $balance['earned'],
            daysTakenBefore: $balance['taken'],
            basisOre: $balance['basis_ore'],
            payoutWithSalary: (bool) $employment->vacation_payout_with_salary,
        );
    }

    /**
     * Vacation balances on a day for many employees with a fixed number of
     * queries: the absences and the vacation-pay bases are read once.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, Employment>  $employments  by employee id
     * @param  array<string, mixed>  $settings
     * @param  array<int, ?list<string>>  $weekPatterns  by employee id
     * @param  ?CarbonImmutable  $takenThrough  count days taken up to and including this day instead of up to the day before
     * @return array<int, array{year_start: CarbonImmutable, year_end: CarbonImmutable, earned: int, taken: int, left: int, basis_ore: int, payout_with_salary: bool}>
     */
    public function balances(Collection $employees, Collection $employments, CarbonImmutable $day, array $settings, RuleSet $rules, array $weekPatterns = [], ?CarbonImmutable $takenThrough = null): array
    {
        $through = $takenThrough ?? $day->subDay();
        $years = self::years($day, (int) ($settings['vacation_year_start_month'] ?? 4));
        [$earningFrom, $earningTo] = $years['earning'];
        [$yearFrom] = $years['year'];
        $ids = $employees->pluck('id')->all();

        $absences = Absence::query()
            ->whereIn('employee_id', $ids)
            ->where('status', 'APPROVED')
            ->whereDate('date_from', '<=', $through->toDateString())
            ->whereDate('date_to', '>=', $earningFrom->toDateString())
            ->orderBy('date_from')
            ->get()
            ->groupBy('employee_id');

        $bases = DB::table('payslip_lines')
            ->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->join('pay_codes', 'pay_codes.id', '=', 'payslip_lines.pay_code_id')
            ->whereIn('payslips.employee_id', $ids)
            ->where('pay_codes.is_vacation_basis', true)
            ->whereNotIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->whereBetween('payroll_runs.payment_date', [$earningFrom->toDateString(), $earningTo->toDateString()])
            ->groupBy('payslips.employee_id')
            ->selectRaw('payslips.employee_id, SUM(payslip_lines.amount_ore) AS basis')
            ->pluck('basis', 'payslips.employee_id');

        $limits = array_map('intval', (array) $rules->get('vacation.qualifying_absence_days'));
        $result = [];

        foreach ($employees as $employee) {
            $employment = $employments->get($employee->id);
            $own = $absences->get($employee->id, collect());
            $earned = $employment === null ? 0 : $this->earned($employment, $own, $earningFrom, $earningTo, $limits, $rules);
            $taken = $this->taken($own, $yearFrom, $through, $weekPatterns[$employee->id] ?? null);
            $payout = (bool) $employment?->vacation_payout_with_salary;

            $result[$employee->id] = [
                'year_start' => $yearFrom,
                'year_end' => $years['year'][1],
                'earned' => $payout ? 0 : $earned,
                'taken' => $taken,
                'left' => $payout ? 0 : max(0, $earned - $taken),
                'basis_ore' => (int) ($bases[$employee->id] ?? 0),
                'payout_with_salary' => $payout,
            ];
        }

        return $result;
    }

    /**
     * Balances on a day for display, with each employment's schedule.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, Employment>  $employments  by employee id
     * @param  array<string, mixed>  $settings
     * @return array<int, array{year_start: CarbonImmutable, year_end: CarbonImmutable, earned: int, taken: int, left: int, basis_ore: int, payout_with_salary: bool}>
     */
    public function overview(Collection $employees, Collection $employments, CarbonImmutable $day, array $settings, RuleSet $rules, ?CarbonImmutable $takenThrough = null): array
    {
        $schedules = WorkSchedule::query()->orderBy('id')->get()->keyBy('id');
        $patterns = [];

        foreach ($employments as $employeeId => $employment) {
            $schedule = $employment->work_schedule_id === null ? $schedules->first() : $schedules->get($employment->work_schedule_id);
            $week = $schedule?->pattern['weeks'][0] ?? null;
            $patterns[$employeeId] = is_array($week) ? array_map('strval', $week) : null;
        }

        return $this->balances($employees, $employments, $day, $settings, $rules, $patterns, $takenThrough);
    }

    /**
     * @param  Collection<int, Absence>  $absences
     * @param  array<string, int>  $limits
     */
    private function earned(Employment $employment, Collection $absences, CarbonImmutable $from, CarbonImmutable $to, array $limits, RuleSet $rules): int
    {
        $start = $employment->start_date->max($from);
        $end = ($employment->end_date ?? $to)->min($to);

        if ($start->greaterThan($end)) {
            return 0;
        }

        $employed = (int) $start->diffInDays($end) + 1;
        $used = [];
        $lost = 0;

        foreach ($absences as $absence) {
            for ($day = $absence->date_from->max($start); $day->lessThanOrEqualTo($absence->date_to->min($end)); $day = $day->addDay()) {
                if (in_array($absence->type, self::NEVER_QUALIFYING, true)) {
                    $lost++;

                    continue;
                }

                if (! isset($limits[$absence->type])) {
                    continue;
                }

                $used[$absence->type] = ($used[$absence->type] ?? 0) + 1;

                if ($used[$absence->type] > $limits[$absence->type]) {
                    $lost++;
                }
            }
        }

        $perYear = $employment->vacation_days_per_year ?? (int) ((array) $rules->get('vacation.minimum_days'))['days'];
        $daysInYear = (int) $from->diffInDays($to) + 1;

        return (int) ceil($perYear * max(0, $employed - $lost) / $daysInYear);
    }

    /**
     * @param  Collection<int, Absence>  $absences
     * @param  ?list<string>  $weekPattern
     */
    private function taken(Collection $absences, CarbonImmutable $from, CarbonImmutable $to, ?array $weekPattern): int
    {
        $days = 0;

        foreach ($absences->where('type', 'VACATION') as $absence) {
            for ($day = $absence->date_from->max($from); $day->lessThanOrEqualTo($absence->date_to->min($to)); $day = $day->addDay()) {
                if (ContextBuilder::scheduled($day, $weekPattern) && SwedishHolidays::isVacationDay($day)) {
                    $days++;
                }
            }
        }

        return $days;
    }
}
