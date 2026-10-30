<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Self-service (PAYROLL_MODULE.md B.11): the signed-in employee's own
 * payslips from approved runs. Nobody else's are ever listed.
 */
class MyPayslips extends Component
{
    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'self:payslips'), 404);
        abort_unless((bool) (app(ModuleManager::class)->settings(PayrollModule::KEY, $this->company())['employee_self_service'] ?? true), 404);
    }

    public function render(): View
    {
        $employee = Employee::query()->where('user_id', $this->user()->id)->first();

        return view('payroll::livewire.my-payslips', [
            'employee' => $employee,
            'vacation' => $employee === null ? null : $this->vacation($employee),
            'payslips' => $employee === null ? collect() : Payslip::query()
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
                ->where('payslips.employee_id', $employee->id)
                ->whereNotIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
                ->orderByDesc('payroll_runs.payment_date')
                ->get(['payslips.*', 'payroll_runs.period', 'payroll_runs.payment_date']),
        ]);
    }

    /**
     * The employee's vacation today, or null while the rules are not checked.
     *
     * @return ?array{year_start: CarbonImmutable, year_end: CarbonImmutable, earned: int, taken: int, left: int, basis_ore: int, payout_with_salary: bool}
     */
    private function vacation(Employee $employee): ?array
    {
        $employment = Employment::query()->where('employee_id', $employee->id)->whereNull('end_date')->latest('start_date')->first();
        $today = CarbonImmutable::today();

        if ($employment === null) {
            return null;
        }

        try {
            return app(VacationLedger::class)->overview(collect([$employee]), collect([$employee->id => $employment]), $today,
                app(ModuleManager::class)->settings(PayrollModule::KEY, $this->company()), RuleSet::for($today), $today)[$employee->id];
        } catch (MissingPayrollRuleException) {
            return null;
        }
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
