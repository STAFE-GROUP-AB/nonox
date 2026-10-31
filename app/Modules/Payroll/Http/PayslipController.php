<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\PayslipPdf;
use App\Support\Audit\Audit;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * A payslip as a PDF, for payroll staff and for the employee it belongs to.
 * Another company's or another employee's payslip answers 404.
 */
class PayslipController
{
    public function __invoke(Payslip $payslip): Response
    {
        abort_unless(Gate::allows('view', $payslip), 404);

        $run = PayrollRun::query()->findOrFail($payslip->payroll_run_id);

        // Staff opening someone else's payslip (salary, sick pay) is logged; an employee's own is not.
        $user = Auth::user();

        if ($user instanceof User && Employee::query()->whereKey($payslip->employee_id)->value('user_id') !== $user->id) {
            Audit::record('payroll.payslip_viewed', $payslip, ['period' => $run->period], Company::query()->find($payslip->company_id), $user);
        }

        return response(PayslipPdf::render($payslip), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.PayslipPdf::filename($payslip, $run).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}
