<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Laravel\Jetstream\Events\TeamMemberAdded;

/**
 * When someone accepts an invitation, the employee with the same email
 * address gets that login, so the portal shows their payslips at once.
 * Nothing happens when no employee, or more than one, has the address.
 */
class LinkEmployeeLogin
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(TeamMemberAdded $event): void
    {
        $company = $event->team;
        $user = $event->user;

        if (! $company instanceof Company || ! $user instanceof User || ! $this->modules->isEnabled(PayrollModule::KEY, $company)) {
            return;
        }

        CurrentCompany::run($company, function () use ($company, $user): void {
            if (Employee::query()->where('user_id', $user->id)->exists()) {
                return;
            }

            $matches = Employee::query()->whereNull('user_id')->get()
                ->filter(fn (Employee $employee): bool => $employee->email !== null && strcasecmp($employee->email, $user->email) === 0);

            if ($matches->count() !== 1) {
                return;
            }

            $employee = $matches->sole();
            $employee->forceFill(['user_id' => $user->id])->save();
            Audit::record('payroll.employee_login_linked', $employee, ['employee_number' => $employee->employee_number], $company, $user);
        });
    }
}
