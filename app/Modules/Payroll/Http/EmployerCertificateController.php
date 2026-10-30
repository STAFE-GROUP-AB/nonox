<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The data for an arbetsgivarintyg (PAYROLL_MODULE.md B.14) as a CSV file:
 * the employment and, per month for the last thirteen months of it, the
 * pay and the absence days by type. The form itself is filled in at
 * Arbetsgivarintyg.nu or the unemployment fund.
 *
 * TODO(legal-verify): the fields the arbetsgivarintyg asks for, such as
 * worked hours per month for hourly employees.
 */
class EmployerCertificateController
{
    private const int MONTHS = 13;

    private const array ABSENCE_TYPES = ['SICK', 'VAB', 'PARENTAL', 'VACATION', 'LEAVE_UNPAID', 'LEAVE_PAID', 'STUDY', 'MILITARY', 'OTHER'];

    public function __invoke(Request $request, Employee $employee): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = Company::query()->findOrFail($employee->company_id);

        abort_unless(Gate::forUser($user)->allows('manage', [Employee::class, $company]), 404);

        $employment = Employment::query()->where('employee_id', $employee->id)->latest('start_date')->firstOrFail();
        $last = ($employment->end_date ?? CarbonImmutable::today())->startOfMonth();
        $first = $last->subMonths(self::MONTHS - 1)->max($employment->start_date->startOfMonth());
        $runs = PayrollRun::query()
            ->whereNotIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->whereBetween('period', [$first->format('Y-m'), $last->format('Y-m')])
            ->pluck('period', 'id');
        $payslips = Payslip::query()->where('employee_id', $employee->id)->whereIn('payroll_run_id', $runs->keys())->get();
        $payoutCode = PayCode::query()->where('code', 'SEMESTERERSATTNING')->value('id');
        $payouts = PayslipLine::query()->whereIn('payslip_id', $payslips->pluck('id'))->where('pay_code_id', $payoutCode)->get()->groupBy('payslip_id');
        $absences = Absence::query()->where('employee_id', $employee->id)->where('status', 'APPROVED')
            ->whereDate('date_to', '>=', $first->toDateString())->whereDate('date_from', '<=', $last->endOfMonth()->toDateString())->get();

        Audit::record('payroll.employer_certificate_exported', $employee, ['from' => $first->format('Y-m'), 'to' => $last->format('Y-m')], $company, $user);

        $filename = "Arbetsgivarintyg-underlag-{$employee->employee_number}.csv";

        return response()->streamDownload(function () use ($employee, $employment, $first, $last, $runs, $payslips, $payouts, $absences): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\u{FEFF}");
            fputcsv($out, [__('Anställd'), "{$employee->first_name} {$employee->last_name}"], ';');
            fputcsv($out, [__('Anställd från'), $employment->start_date->toDateString()], ';');
            fputcsv($out, [__('Anställd till'), $employment->end_date?->toDateString() ?? ''], ';');
            fputcsv($out, [__('Befattning'), (string) $employment->position_title], ';');
            fputcsv($out, [__('Anställningsform'), $employment->employment_type], ';');
            fputcsv($out, [__('Sysselsättningsgrad, %'), (string) intdiv($employment->employment_rate_bp, 100)], ';');
            fputcsv($out, [__('Timmar per vecka'), (string) $employment->weekly_hours], ';');
            fputcsv($out, [], ';');
            fputcsv($out, [__('Månad'), __('Bruttolön, kr'), __('Varav semesterersättning, kr'), ...array_map(fn (string $type): string => __('Frånvarodagar :type', ['type' => $type]), self::ABSENCE_TYPES)], ';');

            for ($month = $first; $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
                $period = $month->format('Y-m');
                $monthPayslips = $payslips->filter(fn (Payslip $payslip): bool => $runs->get($payslip->payroll_run_id) === $period);
                $gross = $monthPayslips->sum(fn (Payslip $payslip): int => $payslip->gross_ore->ore);
                $payout = $monthPayslips->sum(fn (Payslip $payslip): int => (int) $payouts->get($payslip->id)?->sum(fn (PayslipLine $line): int => $line->amount_ore->ore));
                $days = array_map(function (string $type) use ($absences, $month): int {
                    $count = 0;

                    foreach ($absences->where('type', $type) as $absence) {
                        $from = $absence->date_from->max($month);
                        $to = $absence->date_to->min($month->endOfMonth());
                        $count += $from->lessThanOrEqualTo($to) ? (int) $from->diffInDays($to) + 1 : 0;
                    }

                    return $count;
                }, self::ABSENCE_TYPES);

                fputcsv($out, [$period, number_format($gross / 100, 2, ',', ''), number_format($payout / 100, 2, ',', ''), ...$days], ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, max-age=0']);
    }
}
