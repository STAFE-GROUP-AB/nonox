<?php

declare(strict_types=1);

namespace App\Modules\Planning\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\Models\IcalToken;
use App\Modules\Planning\Models\ShiftRequest;
use App\Modules\Planning\PlanningModule;
use App\Support\Tenancy\CompanyScope;

/**
 * Availability, requests and calendar links: an employee reaches only their
 * own (the employee record linked to the login, in the same company), the
 * colleague named in a swap answers it, and planners decide requests. hasTeamPermission checks the membership first
 * and is false while the module is off.
 */
class SelfServicePolicy
{
    public function selfService(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, PlanningModule::SELF_SERVICE);
    }

    public function decide(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'planning:manage');
    }

    public function own(User $user, Availability|ShiftRequest|IcalToken $model): bool
    {
        return $this->isEmployee($user, $model, $model instanceof ShiftRequest ? $model->requested_by : $model->employee_id);
    }

    /** Only the colleague named in a swap answers it. */
    public function answer(User $user, ShiftRequest $request): bool
    {
        return $request->kind === ShiftRequest::SWAP
            && $request->counterpart_employee_id !== null
            && $this->isEmployee($user, $request, $request->counterpart_employee_id);
    }

    private function isEmployee(User $user, Availability|ShiftRequest|IcalToken $model, int $employeeId): bool
    {
        $company = Company::query()->find($model->company_id);

        if (! $company instanceof Company || ! $this->selfService($user, $company)) {
            return false;
        }

        // The row names its company; the employee must be the user's in that same company.
        return CompanyScope::bypass(fn (): bool => Employee::query()
            ->whereKey($employeeId)
            ->where('company_id', $model->company_id)
            ->where('user_id', $user->id)
            ->exists());
    }
}
