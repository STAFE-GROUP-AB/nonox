<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;

/**
 * Employees: payroll staff see everyone; an employee sees only themselves
 * (PAYROLL_MODULE.md B.11). Module permissions are already false while
 * payroll is off (User::hasTeamPermission).
 */
class EmployeePolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'payroll:view');
    }

    public function view(User $user, Employee $employee): bool
    {
        $company = Company::query()->find($employee->company_id);

        if (! $company instanceof Company) {
            return false;
        }

        if ($user->hasTeamPermission($company, 'payroll:view')) {
            return true;
        }

        return $employee->user_id === $user->id && $user->hasTeamPermission($company, 'self:payslips');
    }

    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'employees:manage');
    }

    /** Absence causes and other sensitive data (B.15). */
    public function viewSensitive(User $user, Employee $employee): bool
    {
        $company = Company::query()->find($employee->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, 'payroll:view-sensitive');
    }
}
