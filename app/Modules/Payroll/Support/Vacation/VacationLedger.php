<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Vacation;

use App\Modules\Payroll\Domain\Engine\VacationInput;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\VacationOpeningBalance;
use App\Modules\Payroll\Models\VacationYearClosing;
use App\Modules\Payroll\Models\WorkSchedule;
use App\Modules\Payroll\Support\Calendar\VacationDays;
use App\Modules\Payroll\Support\Run\ContextBuilder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
 * - Saved days from closed semesterår (VacationYearClosing) are added to
 *   the days left and used after the year's own days.
 */
final class VacationLedger
{
    /** Whether a payslip line is vacation-pay basis: its own flag when it has one, else its pay code's. */
    public const string VACATION_BASIS_SQL = "COALESCE((payslip_lines.explanation->>'vacation_basis')::boolean, pay_codes.is_vacation_basis)";

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
    public function input(Employee $employee, Employment $employment, CarbonImmutable $periodStart, array $settings, RuleSet $rules, ?array $weekPattern, ?int $runId = null): VacationInput
    {
        $balance = $this->balances(collect([$employee]), collect([$employee->id => $employment]), $periodStart, $settings, $rules, [$employee->id => $weekPattern])[$employee->id];
        $payouts = [];

        foreach (VacationYearClosing::query()->where('employee_id', $employee->id)->where(fn ($query) => $query->whereNull('payroll_run_id')->when($runId !== null, fn ($own) => $own->orWhere('payroll_run_id', $runId)))->orderBy('year_start')->get() as $closing) {
            $year = "{$closing->year_start->year}/{$closing->year_end->year}";

            if ($closing->payout_ore > 0) {
                $payouts[] = ['description' => __('Semesterlön för :days ej uttagna dagar, semesteråret :year', ['days' => $closing->days_paid_out, 'year' => $year]), 'amount_ore' => $closing->payout_ore, 'closing_id' => $closing->id];
            }

            if ($closing->variable_pay_ore > 0) {
                $payouts[] = ['description' => __('Semesterlön på rörlig lön, semesteråret :year', ['year' => $year]), 'amount_ore' => $closing->variable_pay_ore, 'closing_id' => $closing->id];
            }
        }

        return new VacationInput(
            rule: $employment->vacation_rule ?? (string) ($settings['vacation_rule_default'] ?? 'SAMMALONEREGELN'),
            paidDaysEarned: $balance['earned'],
            daysTakenBefore: $balance['taken'],
            basisOre: $balance['basis_ore'],
            payoutWithSalary: (bool) $employment->vacation_payout_with_salary,
            savedDays: $balance['saved'],
            yearEndPayouts: $payouts,
            countMethod: self::method($settings),
            payoutTax: ($settings['vacation_payout_tax'] ?? 'TABLE') === 'ONE_OFF' ? 'ONE_OFF' : 'TABLE',
            termination: $this->termination($employee, $employment, $periodStart, $settings, $rules, $runId),
        );
    }

    /**
     * Semesterersättning when the employment ends in the period (28 §): the
     * paid days left and the vacation earned in the running earning year,
     * valued as in the semesterlöneskuld on the last day of employment. Paid
     * once; a run that already paid it for the employment is not repeated.
     *
     * TODO(legal-verify): the value per day under 29 § without a collective
     * agreement, and deduction for vacation taken in advance (förskottssemester).
     *
     * @param  array<string, mixed>  $settings
     * @return ?array{amount_ore: int, days: int, earning: string}
     */
    private function termination(Employee $employee, Employment $employment, CarbonImmutable $periodStart, array $settings, RuleSet $rules, ?int $runId): ?array
    {
        $end = $employment->end_date;

        if ($end === null || $employment->vacation_payout_with_salary || $end->lessThan($periodStart) || $end->greaterThan($periodStart->endOfMonth())) {
            return null;
        }

        $paid = PayslipLine::query()
            ->whereIn('payslip_id', Payslip::query()
                ->where('employment_id', $employment->id)
                ->whereIn('payroll_run_id', PayrollRun::query()->whereNotIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])->when($runId !== null, fn ($query) => $query->whereKeyNot($runId))->select('id'))
                ->select('id'))
            ->where('explanation->termination', true)
            ->exists();

        if ($paid) {
            return null;
        }

        $value = $this->liability(collect([$employee]), collect([$employee->id => $employment]), $end, $settings, $rules)[$employee->id] ?? null;

        if ($value === null || $value['liability_ore'] <= 0) {
            return null;
        }

        return ['amount_ore' => $value['liability_ore'], 'days' => $value['left'], 'earning' => $value['earning']];
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
     * @return array<int, array{year_start: CarbonImmutable, year_end: CarbonImmutable, earned: int, taken: int, saved: int, left: int, basis_ore: int, payout_with_salary: bool}>
     */
    public function balances(Collection $employees, Collection $employments, CarbonImmutable $day, array $settings, RuleSet $rules, array $weekPatterns = [], ?CarbonImmutable $takenThrough = null): array
    {
        $through = $takenThrough ?? $day->subDay();
        $years = self::years($day, (int) ($settings['vacation_year_start_month'] ?? 4));
        [$earningFrom, $earningTo] = $years['earning'];
        [$yearFrom] = $years['year'];
        $ids = array_values($employees->map(fn (Employee $employee): int => $employee->id)->all());

        $absences = Absence::query()
            ->whereIn('employee_id', $ids)
            ->where('status', 'APPROVED')
            ->whereDate('date_from', '<=', $through->toDateString())
            ->whereDate('date_to', '>=', $earningFrom->toDateString())
            ->orderBy('date_from')
            ->get()
            ->groupBy('employee_id');

        $bases = $this->bases($ids, $earningFrom, $earningTo);
        $saved = $this->savedDays($ids, $yearFrom);

        $limits = array_map('intval', (array) $rules->get('vacation.qualifying_absence_days'));
        $method = self::method($settings);
        // Balances carried over from an earlier payroll system replace what this system cannot know about that year.
        $openings = VacationOpeningBalance::query()->whereIn('employee_id', $ids)->whereDate('year_start', $yearFrom->toDateString())->get()->keyBy('employee_id');
        $result = [];

        foreach ($employees as $employee) {
            $employment = $employments->get($employee->id);
            $own = $absences->get($employee->id, collect());
            $opening = $openings->get($employee->id);
            $earned = match (true) {
                $opening !== null => $opening->days_earned,
                $employment === null => 0,
                default => $this->earned($employment, $own, $earningFrom, $earningTo, $limits, $rules, self::scale($method, $weekPatterns[$employee->id] ?? null)),
            };
            $taken = $this->taken($own, $yearFrom, $through, $weekPatterns[$employee->id] ?? null, $method) + (int) $opening?->days_taken;
            $payout = (bool) $employment?->vacation_payout_with_salary;
            $pool = ($saved[$employee->id] ?? 0) + (int) $opening?->days_saved;
            $imputed = $opening?->basis_ore !== null || $employment === null ? 0 : $this->imputedIncome($employment, $own, $earningFrom, $earningTo, $limits, $weekPatterns[$employee->id] ?? null);

            $result[$employee->id] = [
                'year_start' => $yearFrom,
                'year_end' => $years['year'][1],
                'earned' => $payout ? 0 : $earned,
                'taken' => $taken,
                'saved' => $payout ? 0 : $pool,
                'left' => $payout ? 0 : max(0, $earned + $pool - $taken),
                'basis_ore' => $opening->basis_ore ?? ((int) ($bases[$employee->id] ?? 0) + $imputed),
                'payout_with_salary' => $payout,
            ];
        }

        return $result;
    }

    /**
     * The vacation-pay basis of approved payslips paid between two days, by
     * employee (16 b §): the pay codes marked as vacation basis, without pay
     * for days of qualifying absence (sick pay and its deductions), and with
     * deductions for unpaid leave and unpaid or vacation days. Income for
     * qualifying absence is added separately (imputedIncome).
     *
     * @param  list<int>  $ids
     * @return Collection<int|string, mixed>
     */
    public function bases(array $ids, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::table('payslip_lines')
            ->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->join('pay_codes', 'pay_codes.id', '=', 'payslip_lines.pay_code_id')
            ->whereIn('payslips.employee_id', $ids)
            ->whereNotIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->whereBetween('payroll_runs.payment_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($query) => $query
                // A line may carry its own flag (paid comp time follows the overtime it was earned on, CompTimeLedger).
                ->where(fn ($basis) => $basis->whereRaw(self::VACATION_BASIS_SQL)->where('pay_codes.category', '<>', 'SICK'))
                ->orWhereIn('pay_codes.code', ['OBETALD_SEMESTER', 'SEMESTERAVDRAG'])
                ->orWhere(fn ($leave) => $leave->where('pay_codes.code', 'FRANVAROAVDRAG')->whereIn(DB::raw("payslip_lines.explanation->>'type'"), self::NEVER_QUALIFYING)))
            ->groupBy('payslips.employee_id')
            ->selectRaw('payslips.employee_id, SUM(payslip_lines.amount_ore) AS basis')
            ->pluck('basis', 'payslips.employee_id');
    }

    /**
     * Days saved from earlier semesterår still within five years (18 §), less
     * the saved days already used, by employee.
     *
     * TODO(legal-verify): saved days that expire unused.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function savedDays(array $ids, CarbonImmutable $yearFrom): array
    {
        $saved = [];

        foreach (VacationYearClosing::query()->whereIn('employee_id', $ids)->whereDate('year_start', '<', $yearFrom->toDateString())->get() as $closing) {
            $valid = $closing->year_end->addYears(5)->greaterThanOrEqualTo($yearFrom) ? $closing->days_saved : 0;
            $saved[$closing->employee_id] = ($saved[$closing->employee_id] ?? 0) + $valid - $closing->saved_days_used;
        }

        return array_map(fn (int $days): int => max(0, $days), $saved);
    }

    /**
     * For hourly pay: the income the employee would have had on days of
     * qualifying absence in the earning year, by the hours of those days at
     * the hourly rate (16 b § tredje stycket). Monthly pay keeps the salary
     * for those days in the basis already.
     *
     * @param  Collection<int, Absence>  $absences
     * @param  array<string, int>  $limits
     * @param  ?list<string>  $weekPattern
     */
    private function imputedIncome(Employment $employment, Collection $absences, CarbonImmutable $from, CarbonImmutable $to, array $limits, ?array $weekPattern): int
    {
        if ($employment->salary_form !== 'HOURLY' || $employment->hourly_rate_ore === null) {
            return 0;
        }

        $used = [];
        $hours = BigDecimal::zero();

        foreach ($absences as $absence) {
            if (! isset($limits[$absence->type]) || $absence->hours === null) {
                continue;
            }

            for ($day = $absence->date_from->max($from); $day->lessThanOrEqualTo($absence->date_to->min($to)); $day = $day->addDay()) {
                $used[$absence->type] = ($used[$absence->type] ?? 0) + 1;

                if ($used[$absence->type] <= $limits[$absence->type] && ContextBuilder::scheduled($day, $weekPattern)) {
                    $hours = $hours->plus($absence->hours);
                }
            }
        }

        return $hours->multipliedBy($employment->hourly_rate_ore->ore)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * The vacation liability (semesterlöneskuld) at the end of a day, per
     * employee: the paid days left in the semesterår plus the days being
     * earned in the current intjänandeår, valued at what they will cost, and
     * the employer's contributions on it.
     *
     * - Sammalöneregeln: a day is worth the company's day value (setting,
     *   % of the monthly salary) plus the supplement.
     * - Procentregeln: days left are worth 12 % of the earning year's basis
     *   per paid day; the year being earned is 12 % of its basis so far.
     * - Contributions use the rate of the employee's latest approved payslip,
     *   or the full rate.
     *
     * TODO(legal-verify): valuation of the liability under sammalöneregeln
     * (BFN guidance) and contributions for employees with reduced rates.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, Employment>  $employments  by employee id
     * @param  array<string, mixed>  $settings
     * @return array<int, array{name: string, left: int, earning: string, liability_ore: int, contributions_ore: int}>
     */
    public function liability(Collection $employees, Collection $employments, CarbonImmutable $day, array $settings, RuleSet $rules): array
    {
        $balances = $this->overview($employees, $employments, $day, $settings, $rules, $day);
        $patterns = $this->patterns($employments);
        $years = self::years($day, (int) ($settings['vacation_year_start_month'] ?? 4));
        [$yearFrom, $yearTo] = $years['year'];
        $ids = array_values($employees->map(fn (Employee $employee): int => $employee->id)->all());
        $current = $this->bases($ids, $yearFrom, $day);
        $limits = array_map('intval', (array) $rules->get('vacation.qualifying_absence_days'));
        $absences = Absence::query()->whereIn('employee_id', $ids)->where('status', 'APPROVED')
            ->whereDate('date_from', '<=', $day->toDateString())->whereDate('date_to', '>=', $yearFrom->toDateString())->get()->groupBy('employee_id');
        $percentBp = (int) ((array) $rules->get('vacation.percent_rule'))['bp'];
        $fullRateBp = (int) ((array) $rules->get('employer_contribution.full'))['bp'];
        $rates = $this->contributionRates($ids);
        $result = [];

        foreach ($employees as $employee) {
            $employment = $employments->get($employee->id);
            $balance = $balances[$employee->id] ?? null;

            if ($employment === null || $balance === null || $balance['payout_with_salary']) {
                continue;
            }

            $earning = $this->earnedExact($employment, $absences->get($employee->id, collect()), $yearFrom, $yearTo, $day, $limits, $rules)->multipliedBy(self::scale(self::method($settings), $patterns[$employee->id] ?? null));
            $monthly = $employment->salary_form === 'MONTHLY';
            $rule = $monthly ? ($employment->vacation_rule ?? (string) ($settings['vacation_rule_default'] ?? 'SAMMALONEREGELN')) : 'PROCENTREGELN';

            if ($rule === 'SAMMALONEREGELN') {
                $supplementBp = (int) ((array) $rules->get('vacation.supplement_sammaloneregeln'))['bp_of_monthly_salary_per_day'];
                $dayValueBp = BigDecimal::of(str_replace(',', '.', (string) ($settings['vacation_day_value_percent'] ?? '4.6')))->multipliedBy(100)->plus($supplementBp);
                $salary = BigDecimal::of((int) $employment->monthly_salary_ore?->ore)->multipliedBy($employment->employment_rate_bp)->dividedBy(10000, 10, RoundingMode::HalfUp);
                $value = $salary->multipliedBy($dayValueBp)->dividedBy(10000, 10, RoundingMode::HalfUp)->multipliedBy($earning->plus($balance['left']));
            } else {
                $perDay = $balance['earned'] > 0
                    ? BigDecimal::of($balance['basis_ore'])->multipliedBy($percentBp)->dividedBy(10000, 10, RoundingMode::HalfUp)->dividedBy($balance['earned'], 10, RoundingMode::HalfUp)
                    : BigDecimal::zero();
                $value = $perDay->multipliedBy($balance['left'])->plus(BigDecimal::of((int) ($current[$employee->id] ?? 0))->multipliedBy($percentBp)->dividedBy(10000, 10, RoundingMode::HalfUp));
            }

            $liability = $value->toScale(0, RoundingMode::HalfUp)->toInt();
            $rateBp = $rates[$employee->id] ?? $fullRateBp;

            $result[$employee->id] = [
                'name' => "{$employee->first_name} {$employee->last_name}",
                'left' => $balance['left'],
                'earning' => (string) $earning->toScale(2, RoundingMode::HalfUp),
                'liability_ore' => $liability,
                'contributions_ore' => BigDecimal::of($liability)->multipliedBy($rateBp)->dividedBy(10000, 0, RoundingMode::HalfUp)->toInt(),
            ];
        }

        return $result;
    }

    /**
     * Each employee's contribution rate on the latest approved payslip, in basis points.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function contributionRates(array $ids): array
    {
        $latest = DB::table('payslips')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->whereIn('payslips.employee_id', $ids)
            ->whereNotIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->where('payslips.contribution_basis_ore', '>', 0)
            ->orderBy('payroll_runs.payment_date')
            ->get(['payslips.employee_id', 'payslips.employer_contribution_ore', 'payslips.contribution_basis_ore']);
        $rates = [];

        foreach ($latest as $row) {
            $rates[(int) $row->employee_id] = (int) round((int) $row->employer_contribution_ore * 10000 / (int) $row->contribution_basis_ore);
        }

        return $rates;
    }

    /**
     * Balances on a day for display, with each employment's schedule.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, Employment>  $employments  by employee id
     * @param  array<string, mixed>  $settings
     * @return array<int, array{year_start: CarbonImmutable, year_end: CarbonImmutable, earned: int, taken: int, saved: int, left: int, basis_ore: int, payout_with_salary: bool}>
     */
    public function overview(Collection $employees, Collection $employments, CarbonImmutable $day, array $settings, RuleSet $rules, ?CarbonImmutable $takenThrough = null): array
    {
        $patterns = $this->patterns($employments);

        return $this->balances($employees, $employments, $day, $settings, $rules, $patterns, $takenThrough);
    }

    /**
     * Each employment's week pattern, by employee id, with one query.
     *
     * @param  Collection<int, Employment>  $employments  by employee id
     * @return array<int, ?list<string>>
     */
    private function patterns(Collection $employments): array
    {
        $schedules = WorkSchedule::query()->orderBy('id')->get()->keyBy('id');
        $patterns = [];

        foreach ($employments as $employeeId => $employment) {
            $schedule = $employment->work_schedule_id === null ? $schedules->first() : $schedules->get($employment->work_schedule_id);
            $week = $schedule?->pattern['weeks'][0] ?? null;
            $patterns[(int) $employeeId] = is_array($week) ? array_map('strval', $week) : null;
        }

        return $patterns;
    }

    /**
     * @param  Collection<int, Absence>  $absences
     * @param  array<string, int>  $limits
     */
    private function earned(Employment $employment, Collection $absences, CarbonImmutable $from, CarbonImmutable $to, array $limits, RuleSet $rules, BigDecimal $scale): int
    {
        return $this->earnedExact($employment, $absences, $from, $to, $to, $limits, $rules)->multipliedBy($scale)->toScale(0, RoundingMode::Up)->toInt();
    }

    /**
     * The company's way of counting semesterdagar (VacationDays).
     *
     * @param  array<string, mixed>  $settings
     */
    public static function method(array $settings): string
    {
        return ($settings['vacation_day_method'] ?? VacationDays::NET) === VacationDays::GROSS ? VacationDays::GROSS : VacationDays::NET;
    }

    /**
     * The share of the year's days someone earns under the net method: their
     * working days a week over five; everyone earns the full days otherwise.
     *
     * @param  ?list<string>  $weekPattern
     */
    public static function scale(string $method, ?array $weekPattern): BigDecimal
    {
        $days = min(VacationDays::FULL_WEEK_DAYS, VacationDays::daysPerWeek($weekPattern));

        return $method === VacationDays::NET && $days < VacationDays::FULL_WEEK_DAYS
            ? BigDecimal::of($days)->dividedBy(VacationDays::FULL_WEEK_DAYS, 10, RoundingMode::HalfUp)
            : BigDecimal::one();
    }

    /**
     * Paid days earned in an intjänandeår up to a day, not rounded (7 §).
     *
     * @param  Collection<int, Absence>  $absences
     * @param  array<string, int>  $limits
     */
    private function earnedExact(Employment $employment, Collection $absences, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $through, array $limits, RuleSet $rules): BigDecimal
    {
        $start = $employment->start_date->max($from);
        $end = ($employment->end_date ?? $to)->min($to)->min($through);

        if ($start->greaterThan($end)) {
            return BigDecimal::zero();
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

        return BigDecimal::of($perYear)->multipliedBy(max(0, $employed - $lost))->dividedBy($daysInYear, 10, RoundingMode::HalfUp);
    }

    /**
     * @param  Collection<int, Absence>  $absences
     * @param  ?list<string>  $weekPattern
     */
    private function taken(Collection $absences, CarbonImmutable $from, CarbonImmutable $to, ?array $weekPattern, string $method): int
    {
        $days = 0;
        $scheduled = fn (CarbonImmutable $day): bool => ContextBuilder::scheduled($day, $weekPattern);

        foreach ($absences->where('type', 'VACATION') as $absence) {
            $spell = [];

            for ($day = $absence->date_from; $day->lessThanOrEqualTo($absence->date_to); $day = $day->addDay()) {
                $spell[] = $day;
            }

            foreach (VacationDays::counted($spell, $scheduled, $method) as $day) {
                if ($day->betweenIncluded($from, $to)) {
                    $days++;
                }
            }
        }

        return $days;
    }
}
