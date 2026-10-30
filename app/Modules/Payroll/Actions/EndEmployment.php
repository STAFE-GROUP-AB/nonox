<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ends an employment on its last day (PAYROLL_MODULE.md B.14). The run for
 * that month pays salary up to the day and semesterersättning for the
 * vacation left (28 §); later runs leave the employee out. The last day
 * cannot fall in a month that is already approved, since that pay is fixed.
 */
class EndEmployment
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function __invoke(User $user, Company $company, Employee $employee, string $lastDay, ?string $reason = null): Employment
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);
        Gate::forUser($user)->authorize('manage', [Employee::class, $company]);

        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastDay) === 1 ? CarbonImmutable::parse($lastDay) : null;

        if ($day === null) {
            throw ValidationException::withMessages(['end_date' => [__('Välj den sista anställningsdagen.')]]);
        }

        return CurrentCompany::run($company, fn (): Employment => DB::transaction(function () use ($user, $company, $employee, $day, $reason): Employment {
            $employment = Employment::query()->where('employee_id', $employee->id)->whereNull('end_date')->latest('start_date')->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['end_date' => [__('Den anställde har ingen pågående anställning.')]]);

            if ($day->lessThan($employment->start_date)) {
                throw ValidationException::withMessages(['end_date' => [__('Den sista dagen kan inte vara före anställningens första dag.')]]);
            }

            $fixed = PayrollRun::query()
                ->whereIn('id', Payslip::query()->where('employment_id', $employment->id)->select('payroll_run_id'))
                ->whereNotIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
                ->where('period', '>=', $day->format('Y-m'))
                ->orderBy('period')
                ->first();

            if ($fixed !== null) {
                throw ValidationException::withMessages(['end_date' => [__('Lönen för :period är redan attesterad. Den sista dagen kan inte ligga i eller före en attesterad månad.', ['period' => $fixed->period])]]);
            }

            $employment->forceFill(['end_date' => $day])->save();
            $employee->forceFill(['status' => 'TERMINATED'])->save();

            Audit::record('payroll.employment_ended', $employee, ['end_date' => $day->toDateString(), 'reason' => blank($reason) ? null : mb_substr(trim((string) $reason), 0, 255)], $company, $user);

            return $employment;
        }));
    }
}
