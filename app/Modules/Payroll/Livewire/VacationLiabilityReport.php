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
use App\Modules\Payroll\Models\VacationLiabilitySnapshot;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Semesterlöneskuld per employee on a day, and what is booked so far.
 */
class VacationLiabilityReport extends Component
{
    #[Url(as: 'datum', except: '')]
    public string $date = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'payroll:view'), 403);
    }

    public function render(): View
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1 ? CarbonImmutable::parse($this->date) : CarbonImmutable::today();
        $employments = Employment::query()
            ->whereDate('start_date', '<=', $day->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $day->toDateString()))
            ->orderBy('start_date')
            ->get()
            ->keyBy('employee_id');
        $employees = Employee::query()->whereIn('id', $employments->keys())->orderBy('employee_number')->get();
        $missing = null;

        try {
            $rows = app(VacationLedger::class)->liability($employees, $employments, $day, app(ModuleManager::class)->settings(PayrollModule::KEY, $this->company()), RuleSet::for($day));
        } catch (MissingPayrollRuleException $exception) {
            $rows = [];
            $missing = $exception->key;
        }

        return view('payroll::livewire.vacation-liability', [
            'day' => $day,
            'rows' => $rows,
            'missing' => $missing,
            'booked' => VacationLiabilitySnapshot::query()->whereDate('as_of', '<=', $day->toDateString())->orderByDesc('as_of')->orderByDesc('id')->first(),
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
