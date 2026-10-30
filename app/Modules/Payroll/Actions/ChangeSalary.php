<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\SalaryChange;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Run\ContextBuilder;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A new monthly salary from the first day of a month (lönerevision). The
 * history starts with the salary the employment had, from its first month.
 * A month already paid at the old salary gets retroactive pay in the next
 * payroll run, as a one-off amount (PAYROLL_MODULE.md B.14).
 */
class ChangeSalary
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function __invoke(User $user, Company $company, Employee $employee, string $validFrom, string $salary, ?string $reason = null): SalaryChange
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);
        Gate::forUser($user)->authorize('manage', [Employee::class, $company]);

        $date = preg_match('/^\d{4}-\d{2}-01$/', $validFrom) === 1 ? CarbonImmutable::parse($validFrom) : null;

        if ($date === null) {
            throw ValidationException::withMessages(['valid_from' => [__('En ny lön gäller från den första dagen i en månad.')]]);
        }

        try {
            $amount = Money::parse($salary);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['salary' => [__('Lönen går inte att läsa. Skriv till exempel 32 000 eller 165,50.')]]);
        }

        if ($amount->isZero() || $amount->isNegative()) {
            throw ValidationException::withMessages(['salary' => [__('Lönen ska vara större än noll.')]]);
        }

        return CurrentCompany::run($company, fn (): SalaryChange => DB::transaction(function () use ($user, $company, $employee, $date, $amount, $reason): SalaryChange {
            $employment = Employment::query()->where('employee_id', $employee->id)->whereNull('end_date')->latest('start_date')->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['valid_from' => [__('Den anställde har ingen pågående anställning.')]]);

            if ($employment->salary_form !== 'MONTHLY') {
                throw ValidationException::withMessages(['salary' => [__('Lönerevision med retroaktiv lön gäller månadslön. Ändra timlönen i personalkortet.')]]);
            }

            $firstMonth = $employment->start_date->startOfMonth();

            if ($date->lessThanOrEqualTo($firstMonth)) {
                throw ValidationException::withMessages(['valid_from' => [__('Ändra lönen i personalkortet för anställningens första månad.')]]);
            }

            app(SeedPayCodes::class)($company);

            if (! SalaryChange::query()->where('employment_id', $employment->id)->exists()) {
                SalaryChange::create([
                    'employee_id' => $employee->id,
                    'employment_id' => $employment->id,
                    'valid_from' => $firstMonth,
                    'monthly_salary_ore' => $employment->monthly_salary_ore,
                    'reason' => __('Lön när historiken började'),
                ]);
            }

            $change = SalaryChange::query()->updateOrCreate(
                ['employment_id' => $employment->id, 'valid_from' => $date->toDateString()],
                ['employee_id' => $employee->id, 'monthly_salary_ore' => $amount, 'reason' => blank($reason) ? null : mb_substr(trim((string) $reason), 0, 255)],
            );

            $employment->forceFill(['monthly_salary_ore' => ContextBuilder::salaryAt($employment, CarbonImmutable::today()->startOfMonth())])->save();

            Audit::record('payroll.salary_changed', $employee, ['valid_from' => $date->toDateString(), 'monthly_salary_ore' => $amount->ore], $company, $user);

            return $change;
        }));
    }
}
