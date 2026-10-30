<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use App\Models\Company;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The payslip as a PDF (PAYROLL_MODULE.md B.11) in our own layout: the
 * employer, period, payment date, every line, gross, tax with table and
 * column, net, year-to-date totals and a masked bank account. Year-to-date
 * comes from the approved payslips of the year up to this one, so an old
 * payslip always looks as it did when it was issued.
 */
final class PayslipPdf
{
    public static function render(Payslip $payslip): string
    {
        $payslip->loadMissing(['lines', 'employee', 'employment']);
        $run = PayrollRun::query()->findOrFail($payslip->payroll_run_id);
        $company = Company::query()->findOrFail($payslip->company_id);
        /** @var Employee $employee */
        $employee = $payslip->employee;

        $earlier = Payslip::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payslips.employee_id', $payslip->employee_id)
            ->whereNotIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->whereYear('payroll_runs.payment_date', $run->payment_date->year)
            ->whereDate('payroll_runs.payment_date', '<=', $run->payment_date->toDateString())
            ->selectRaw('COALESCE(SUM(payslips.gross_ore), 0) AS gross, COALESCE(SUM(payslips.tax_ore), 0) AS tax, COALESCE(SUM(payslips.net_ore), 0) AS net')
            ->toBase()
            ->first();

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('payroll::payslip-pdf', [
            'payslip' => $payslip,
            'run' => $run,
            'company' => $company,
            'employee' => $employee,
            'ytd' => ['gross' => (int) ($earlier->gross ?? 0), 'tax' => (int) ($earlier->tax ?? 0), 'net' => (int) ($earlier->net ?? 0)],
            'account' => self::maskedAccount($employee),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    public static function filename(Payslip $payslip, PayrollRun $run): string
    {
        return "Lonebesked-{$run->period}-{$payslip->employee_id}.pdf";
    }

    private static function maskedAccount(Employee $employee): ?string
    {
        $account = (string) $employee->bank_account;

        return $account === '' ? null : trim($employee->bank_clearing.' ').' ••••'.substr($account, -4);
    }
}
