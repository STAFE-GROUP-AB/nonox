<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Run;

use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\TimeReport;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

/**
 * Approved time reports as hourly pay in a payroll run (GitHub issue #15).
 *
 * - A run pays the approved reports with the hourly pay code dated in its
 *   month, for employments paid by the hour. Monthly-paid employments are
 *   not affected: their hours are covered by the monthly salary.
 * - Reports on a supplement pay code (an hours-based addition such as OB
 *   or overtime, written by Planering's attest) are paid in the run of
 *   their month for any employment in force on the day, monthly-paid too:
 *   they are what the salary does not cover. They are not part of the
 *   question about earlier months (late()); a correction for an attested
 *   month goes into the next run by hand.
 * - The run that pays a report claims it (time_reports.payroll_run_id), so
 *   it is never paid twice; a recalculation releases and claims again, and
 *   an approved run freezes its reports (database trigger).
 * - Reports not yet approved are only counted, so the run page can say how
 *   many hours are waiting. Other modules (the staff ledger's proposals) add
 *   their own waiting hours through addPendingSource().
 * - Approved reports from an earlier month that no run paid (approved after
 *   that month's run was attested) are never paid on their own: the regular
 *   run asks the person running payroll (late()), and pays only the ones
 *   that person chose to include, one line per month and employment.
 */
final class TimeReportPay
{
    public const string PAY_CODE = 'TIMLON';

    /** @var list<Closure(CarbonImmutable, CarbonImmutable): string> */
    private array $pendingSources = [];

    /**
     * @param  Closure(CarbonImmutable, CarbonImmutable): string  $source  hours waiting for approval between two dates
     */
    public function addPendingSource(Closure $source): void
    {
        $this->pendingSources[] = $source;
    }

    /**
     * The approved hourly reports the run may pay, for all the employees at
     * once, locked until the run's transaction ends: not claimed, or claimed
     * by this run already. Reports from earlier months come along only when
     * they are among $lateIds, the ones the person running payroll chose to
     * include.
     *
     * @param  Collection<int, int>  $employeeIds
     * @param  list<int>  $lateIds
     * @return Collection<int, TimeReport>
     */
    public function claimable(PayrollRun $run, Collection $employeeIds, array $lateIds = []): Collection
    {
        $payCodeId = PayCode::query()->where('code', self::PAY_CODE)->value('id');

        if ($payCodeId === null || $employeeIds->isEmpty()) {
            return collect();
        }

        $start = $run->periodStart()->toDateString();
        $supplements = self::supplementCodes()->keys()->all();

        return TimeReport::query()
            ->whereIn('employee_id', $employeeIds->unique()->values())
            ->where(fn ($query) => $query->where('pay_code_id', $payCodeId)
                ->orWhere(fn ($supplement) => $supplement->whereIn('pay_code_id', $supplements)->whereDate('date', '>=', $start)))
            ->where('status', 'APPROVED')
            ->whereDate('date', '<=', $run->periodEnd()->toDateString())
            ->where(fn ($query) => $query->whereDate('date', '>=', $start)
                ->orWhere(fn ($late) => $late->whereIn('id', $lateIds)->whereDate('date', '<', $start)))
            ->where(fn ($query) => $query->whereNull('payroll_run_id')->orWhere('payroll_run_id', $run->id))
            ->orderBy('date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->toBase();
    }

    /**
     * The reports that belong to one employment: the employee's, dated
     * while it was in force, and either the employment is paid by the hour
     * or the report is on a supplement pay code (anything but
     * $hourlyPayCodeId).
     *
     * @param  Collection<int, TimeReport>  $reports  the run's claimable reports
     * @return Collection<int, TimeReport>
     */
    public static function forEmployment(Collection $reports, Employment $employment, ?int $hourlyPayCodeId = null): Collection
    {
        return $reports->filter(fn (TimeReport $report): bool => $report->employee_id === $employment->employee_id
            && ($employment->salary_form === 'HOURLY' || ($hourlyPayCodeId !== null && $report->pay_code_id !== $hourlyPayCodeId))
            && $report->date->greaterThanOrEqualTo($employment->start_date)
            && ($employment->end_date === null || $report->date->lessThanOrEqualTo($employment->end_date)))->values();
    }

    /**
     * Pay codes whose approved time reports a run pays besides hourly pay:
     * hours-based additions (OB, overtime). By id.
     *
     * @return Collection<int, PayCode>
     */
    public static function supplementCodes(): Collection
    {
        return PayCode::query()
            ->where('code', '<>', self::PAY_CODE)
            ->where('unit', 'HOURS')
            ->where('category', 'ADDITION')
            ->get()
            ->keyBy('id');
    }

    /**
     * Approved hourly reports dated before the run's month that no run has
     * paid, for the employees in the run, read in one query: the employee
     * had an employment paid by the hour in force on the day, and the
     * report's month has no regular run still open (that run pays it). Only
     * a regular run asks; the ones the run already has an answer for are
     * left out unless $withDecided.
     *
     * @return Collection<int, TimeReport>
     */
    public function late(PayrollRun $run, bool $withDecided = false): Collection
    {
        $payCodeId = PayCode::query()->where('code', self::PAY_CODE)->value('id');

        if ($payCodeId === null || $run->type !== 'REGULAR') {
            return collect();
        }

        $start = $run->periodStart()->toDateString();
        $end = $run->periodEnd()->toDateString();

        return TimeReport::query()
            ->with('employee:id,first_name,last_name')
            ->where('pay_code_id', $payCodeId)
            ->where('status', 'APPROVED')
            ->whereDate('date', '<', $start)
            ->where(fn ($query) => $query->whereNull('payroll_run_id')->orWhere('payroll_run_id', $run->id))
            ->when(! $withDecided, fn ($query) => $query->whereNotIn('id', $run->lateTimeReportsDecided()))
            // Paid by the hour on the day the hours were worked.
            ->whereExists(fn ($query) => $query->from('employments')
                ->whereColumn('employments.company_id', 'time_reports.company_id')
                ->whereColumn('employments.employee_id', 'time_reports.employee_id')
                ->where('employments.salary_form', 'HOURLY')
                ->whereColumn('employments.start_date', '<=', 'time_reports.date')
                ->where(fn ($inForce) => $inForce->whereNull('employments.end_date')->orWhereColumn('employments.end_date', '>=', 'time_reports.date')))
            // In the run: employed during its month.
            ->whereExists(fn ($query) => $query->from('employments')
                ->whereColumn('employments.company_id', 'time_reports.company_id')
                ->whereColumn('employments.employee_id', 'time_reports.employee_id')
                ->whereDate('employments.start_date', '<=', $end)
                ->where(fn ($inForce) => $inForce->whereNull('employments.end_date')->orWhereDate('employments.end_date', '>=', $start)))
            // Not the question of this run while the report's own month still has an open run.
            ->whereNotExists(fn ($query) => $query->from('payroll_runs')
                ->whereColumn('payroll_runs.company_id', 'time_reports.company_id')
                ->where('payroll_runs.type', 'REGULAR')
                ->whereIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
                ->where('payroll_runs.id', '<>', $run->id)
                ->whereRaw("payroll_runs.period = to_char(time_reports.date, 'YYYY-MM')"))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * The late reports as the run page shows them: per employee the hours
     * and the months they belong to.
     *
     * @param  Collection<int, TimeReport>  $reports
     * @return array{hours: string, employees: list<array{name: string, hours: string, months: list<string>}>}
     */
    public static function summary(Collection $reports): array
    {
        $employees = [];

        foreach ($reports->groupBy('employee_id') as $own) {
            $employee = $own->first()?->employee;
            $months = [];

            foreach ($own as $report) {
                $months[$report->date->format('Y-m')] = $report->date->startOfMonth()->translatedFormat('F Y');
            }

            ksort($months);
            $employees[] = [
                'name' => trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
                'hours' => (string) self::hours($own),
                'months' => array_values($months),
            ];
        }

        usort($employees, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['hours' => (string) self::hours($reports), 'employees' => $employees];
    }

    /**
     * Late reports for one payslip as lines: one per month and employment
     * paid by the hour that was in force on the day, at that employment's
     * hourly rate. A report without such an employment is left out (and so
     * not claimed).
     *
     * @param  Collection<int, TimeReport>  $reports  the employee's late reports the run pays
     * @param  Collection<int, Employment>  $employments  the employee's employments paid by the hour
     * @return list<array{month: CarbonImmutable, rate_ore: ?int, reports: Collection<int, TimeReport>}>
     */
    public static function lateGroups(Collection $reports, Collection $employments): array
    {
        $groups = [];

        foreach ($reports as $report) {
            $employment = $employments->first(fn (Employment $employment): bool => $employment->employee_id === $report->employee_id
                && $employment->salary_form === 'HOURLY'
                && $report->date->greaterThanOrEqualTo($employment->start_date)
                && ($employment->end_date === null || $report->date->lessThanOrEqualTo($employment->end_date)));

            if ($employment === null) {
                continue;
            }

            $key = $report->date->format('Y-m').'|'.$employment->id;
            $groups[$key] ??= ['month' => $report->date->startOfMonth(), 'rate_ore' => $employment->hourly_rate_ore?->ore, 'reports' => collect()];
            $groups[$key]['reports']->push($report);
        }

        ksort($groups);

        return array_values($groups);
    }

    /** @param  Collection<int, TimeReport>  $reports */
    public static function hours(Collection $reports): BigDecimal
    {
        return $reports->reduce(fn (BigDecimal $sum, TimeReport $report): BigDecimal => $sum->plus((string) $report->hours), BigDecimal::zero());
    }

    /**
     * Hours in the period that are reported but not approved, so not paid:
     * submitted time reports, and what other modules say is waiting.
     */
    public function pendingHours(CarbonImmutable $from, CarbonImmutable $to): BigDecimal
    {
        $hours = BigDecimal::of((string) (TimeReport::query()
            ->where('status', 'SUBMITTED')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->sum('hours')));

        foreach ($this->pendingSources as $source) {
            $hours = $hours->plus($source($from, $to));
        }

        return $hours;
    }
}
