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
 * - The run that pays a report claims it (time_reports.payroll_run_id), so
 *   it is never paid twice; a recalculation releases and claims again, and
 *   an approved run freezes its reports (database trigger).
 * - Reports not yet approved are only counted, so the run page can say how
 *   many hours are waiting. Other modules (the staff ledger's proposals) add
 *   their own waiting hours through addPendingSource().
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
     * by this run already.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return Collection<int, TimeReport>
     */
    public function claimable(PayrollRun $run, Collection $employeeIds): Collection
    {
        $payCodeId = PayCode::query()->where('code', self::PAY_CODE)->value('id');

        if ($payCodeId === null || $employeeIds->isEmpty()) {
            return collect();
        }

        return TimeReport::query()
            ->whereIn('employee_id', $employeeIds->unique()->values())
            ->where('pay_code_id', $payCodeId)
            ->where('status', 'APPROVED')
            ->whereDate('date', '>=', $run->periodStart()->toDateString())
            ->whereDate('date', '<=', $run->periodEnd()->toDateString())
            ->where(fn ($query) => $query->whereNull('payroll_run_id')->orWhere('payroll_run_id', $run->id))
            ->orderBy('date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->toBase();
    }

    /**
     * The reports that belong to one employment: the employee's, paid by the
     * hour, and dated while it was in force.
     *
     * @param  Collection<int, TimeReport>  $reports  the run's claimable reports
     * @return Collection<int, TimeReport>
     */
    public static function forEmployment(Collection $reports, Employment $employment): Collection
    {
        if ($employment->salary_form !== 'HOURLY') {
            return collect();
        }

        return $reports->filter(fn (TimeReport $report): bool => $report->employee_id === $employment->employee_id
            && $report->date->greaterThanOrEqualTo($employment->start_date)
            && ($employment->end_date === null || $report->date->lessThanOrEqualTo($employment->end_date)))->values();
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
