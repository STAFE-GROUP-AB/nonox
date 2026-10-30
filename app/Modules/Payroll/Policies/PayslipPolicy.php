<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;

/**
 * Payslips: payroll staff see every payslip; an employee sees only their own,
 * and only once the run is approved (PAYROLL_MODULE.md B.11).
 */
class PayslipPolicy
{
    public function view(User $user, Payslip $payslip): bool
    {
        $company = Company::query()->find($payslip->company_id);

        if (! $company instanceof Company) {
            return false;
        }

        if ($user->hasTeamPermission($company, 'payroll:view') || $user->hasTeamPermission($company, 'payroll:archive-view')) {
            return true;
        }

        $run = PayrollRun::query()->find($payslip->payroll_run_id);
        $employee = Employee::query()->find($payslip->employee_id);

        return $run instanceof PayrollRun
            && ! $run->isOpen()
            && $employee instanceof Employee
            && $employee->user_id === $user->id
            && $user->hasTeamPermission($company, 'self:payslips');
    }
}
