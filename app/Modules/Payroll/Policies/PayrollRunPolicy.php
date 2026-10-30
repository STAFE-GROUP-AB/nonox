<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;

/**
 * Payroll runs: prepared and calculated with payroll:run, approved with
 * payroll:approve by someone other than the calculator when the company
 * requires it (PAYROLL_MODULE.md B.10). An approved run is never changed.
 */
class PayrollRunPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'payroll:view');
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $this->can($user, $run, 'payroll:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'payroll:run');
    }

    public function update(User $user, PayrollRun $run): bool
    {
        return $run->isOpen() && $this->can($user, $run, 'payroll:run');
    }

    public function approve(User $user, PayrollRun $run): bool
    {
        return $run->status === PayrollRun::CALCULATED
            && $this->can($user, $run, 'payroll:approve')
            && ! $this->blockedBySegregation($user, $run);
    }

    public function pay(User $user, PayrollRun $run): bool
    {
        return $run->status === PayrollRun::APPROVED && $this->can($user, $run, 'payroll:run');
    }

    public function post(User $user, PayrollRun $run): bool
    {
        return $run->status === PayrollRun::PAID && $this->can($user, $run, 'payroll:post');
    }

    public function blockedBySegregation(User $user, PayrollRun $run): bool
    {
        $company = Company::query()->find($run->company_id);

        return $company instanceof Company
            && (bool) (app(ModuleManager::class)->settings(PayrollModule::KEY, $company)['segregation_of_duties'] ?? true)
            && $run->calculated_by === $user->id;
    }

    private function can(User $user, PayrollRun $run, string $permission): bool
    {
        $company = Company::query()->find($run->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, $permission);
    }
}
