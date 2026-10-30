<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The payroll runs, newest first, and a form to start the next one.
 */
class PayrollRunList extends Component
{
    use ShowsToasts;

    public string $period = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('viewAny', [PayrollRun::class, $this->company()]), 403);

        $this->period = now()->format('Y-m');
    }

    public function create(PayrollRuns $runs): void
    {
        $run = $runs->create($this->user(), $this->company(), $this->period);

        Toast::flash(__(':title är skapad med utbetalning :date.', ['title' => $run->title(), 'date' => $run->payment_date->toDateString()]));
        $this->redirectRoute('payroll.runs.show', ['run' => $run->id]);
    }

    public function render(): View
    {
        return view('payroll::livewire.run-list', [
            'runs' => PayrollRun::query()->withCount('payslips')->withSum('payslips', 'net_ore')->orderByDesc('period')->get(),
            'canCreate' => Gate::allows('create', [PayrollRun::class, $this->company()]),
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
