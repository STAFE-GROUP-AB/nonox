<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
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
    }

    public function render(): View
    {
        $employee = Employee::query()->where('user_id', $this->user()->id)->first();

        return view('payroll::livewire.my-payslips', [
            'employee' => $employee,
            'payslips' => $employee === null ? collect() : Payslip::query()
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
                ->where('payslips.employee_id', $employee->id)
                ->whereNotIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
                ->orderByDesc('payroll_runs.payment_date')
                ->get(['payslips.*', 'payroll_runs.period', 'payroll_runs.payment_date']),
        ]);
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
