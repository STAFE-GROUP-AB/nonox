<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Lönearkivet: approved payroll runs with their payslips, and the employer
 * declarations, read-only. It stays open while the module is off, for the
 * retention period and for audits (PAYROLL_MODULE.md A.6).
 */
class PayrollArchive extends Component
{
    #[Url(as: 'korning', except: 0)]
    public int $runId = 0;

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'payroll:archive-view'), 403);
    }

    public function render(): View
    {
        $runs = PayrollRun::query()->whereNotIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])->orderByDesc('payment_date')->get();
        $totals = Payslip::query()
            ->whereIn('payroll_run_id', $runs->pluck('id'))
            ->get(['payroll_run_id', 'gross_ore', 'net_ore'])
            ->groupBy('payroll_run_id');
        $open = $runs->firstWhere('id', $this->runId);

        return view('payroll::livewire.archive', [
            'runs' => $runs,
            'totals' => $totals,
            'open' => $open,
            'payslips' => $open === null ? collect() : Payslip::query()->with('employee')->where('payroll_run_id', $open->id)->get()->sortBy(fn (Payslip $payslip): int => (int) $payslip->employee?->employee_number),
            'submissions' => AgiSubmission::query()->orderByDesc('period')->orderByDesc('sequence')->get(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        return $this->user()->currentCompany() ?? abort(404);
    }
}
