<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Who an employee is in Min sida: the employee record linked to the login
 * in the company. Everything an employee does in Planering starts here, so
 * they only ever reach their own schedule, availability, requests and
 * calendar link. Needs Planering on, the self-service permission (a member
 * of the company) and the payroll setting that opens Min sida.
 */
final class SelfService
{
    public function __construct(private readonly ModuleManager $modules) {}

    /** Whether the user can use Planering in Min sida at all; no exception. */
    public function available(User $user, Company $company): bool
    {
        return $this->modules->isEnabled(PlanningModule::KEY, $company)
            && Gate::forUser($user)->allows('selfService', [Availability::class, $company])
            && (bool) ($this->modules->settings(PayrollModule::KEY, $company)['employee_self_service'] ?? true);
    }

    /** The user's own employee record, or null when the login is not linked to one. */
    public function find(User $user, Company $company): ?Employee
    {
        if (! $this->available($user, $company)) {
            return null;
        }

        return CurrentCompany::run($company, fn (): ?Employee => Employee::query()->where('user_id', $user->id)->first());
    }

    public function employee(User $user, Company $company): Employee
    {
        $this->modules->ensureEnabled(PlanningModule::KEY, $company);

        if (! $this->available($user, $company)) {
            throw new AuthorizationException;
        }

        return $this->find($user, $company)
            ?? throw ValidationException::withMessages(['employee' => __('Ditt konto är inte kopplat till någon anställd. Be din arbetsgivare koppla det.')]);
    }

    /** @return array<string, mixed> */
    public function settings(Company $company): array
    {
        return PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));
    }
}
