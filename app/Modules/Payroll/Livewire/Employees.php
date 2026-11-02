<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\OpensFormFromLink;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\ChangeSalary;
use App\Modules\Payroll\Actions\ConfirmBankChange;
use App\Modules\Payroll\Actions\EndEmployment;
use App\Modules\Payroll\Actions\EraseEmployee;
use App\Modules\Payroll\Actions\InviteEmployeeToPortal;
use App\Modules\Payroll\Actions\SaveEmployee;
use App\Modules\Payroll\Actions\SavePensionPlan;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PensionPlan;
use App\Modules\Payroll\Models\SalaryChange;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The employee register: everyone on the payroll, with a form for adding and
 * changing an employee and the current employment.
 */
class Employees extends Component
{
    use OpensFormFromLink;
    use ShowsToasts;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    /** @var array<string, string|bool> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless(Gate::allows('viewAny', [Employee::class, $this->company()]), 403);

        $this->openFormFromLink(Employee::class, 'manage');
    }

    public function create(): void
    {
        abort_unless(Gate::allows('manage', [Employee::class, $this->company()]), 403);

        $this->editingId = null;
        $this->form = [
            'first_name' => '', 'last_name' => '', 'personal_number' => '', 'email' => '', 'phone' => '',
            'address' => '', 'postal_code' => '', 'city' => '', 'bank_clearing' => '', 'bank_account' => '',
            'tax_residence' => 'SE', 'tax_table' => '', 'tax_column' => '1',
            'tax_adjustment_type' => 'NONE', 'tax_adjustment_percent' => '', 'tax_adjustment_amount' => '', 'tax_adjustment_table' => '', 'tax_adjustment_column' => '',
            'tax_adjustment_valid_from' => '', 'tax_adjustment_valid_to' => '',
            'start_date' => now()->toDateString(), 'employment_type' => 'TILLSVIDARE', 'salary_form' => 'MONTHLY',
            'salary' => '', 'employment_rate' => '100', 'weekly_hours' => '40', 'position_title' => '', 'cost_center' => '', 'user_id' => '',
            'vacation_rule' => '', 'vacation_days_per_year' => '25', 'vacation_payout_with_salary' => false, 'pension_plan_id' => '',
        ];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function edit(int $employeeId): void
    {
        abort_unless(Gate::allows('manage', [Employee::class, $this->company()]), 403);

        $employee = Employee::query()->findOrFail($employeeId);
        $employment = Employment::query()->with('costCenter')->where('employee_id', $employee->id)->whereNull('end_date')->latest('start_date')->first()
            ?? Employment::query()->with('costCenter')->where('employee_id', $employee->id)->latest('start_date')->first();

        $this->editingId = $employee->id;
        $this->form = array_map(fn (mixed $value): string => (string) $value, [
            // Personal number and bank details never go into the page state; empty means "keep".
            ...$employee->only(['first_name', 'last_name', 'email', 'phone', 'address', 'postal_code', 'city', 'tax_residence', 'tax_table', 'tax_column', 'user_id']),
            'personal_number' => '', 'bank_clearing' => '', 'bank_account' => '',
            ...SaveEmployee::taxAdjustmentForm($employee),
            'start_date' => $employment?->start_date->toDateString(),
            'employment_type' => $employment === null ? 'TILLSVIDARE' : $employment->employment_type,
            'salary_form' => $employment === null ? 'MONTHLY' : $employment->salary_form,
            'salary' => ($employment?->salary_form === 'HOURLY' ? $employment->hourly_rate_ore : $employment?->monthly_salary_ore)?->format(withCurrency: false),
            'employment_rate' => $employment === null ? '100' : (string) intdiv((int) $employment->employment_rate_bp, 100),
            'weekly_hours' => $employment?->weekly_hours,
            'position_title' => $employment?->position_title,
            'cost_center' => $employment?->costCenter?->code,
            'vacation_rule' => $employment?->vacation_rule,
            'vacation_days_per_year' => $employment === null ? 25 : ($employment->vacation_days_per_year ?? 25),
            'pension_plan_id' => $employment?->pension_plan_id,
        ]);
        $this->form['vacation_payout_with_salary'] = (bool) $employment?->vacation_payout_with_salary;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'editingId', 'form', 'raise', 'ending', 'confirmingEnd');
    }

    /** @var array<string, string> */
    public array $raise = ['valid_from' => '', 'salary' => '', 'reason' => ''];

    /** @var array<string, string> */
    public array $ending = ['end_date' => '', 'reason' => ''];

    public function endEmployment(EndEmployment $end): void
    {
        $employee = Employee::query()->findOrFail((int) $this->editingId);
        $employment = $end($this->user(), $this->company(), $employee, $this->ending['end_date'], $this->ending['reason']);

        $this->reset('ending', 'confirmingEnd');
        $this->toast(__('Anställningen för :name slutar :date. Lönekörningen för den månaden betalar lön till och med dagen och semesterersättning.', ['name' => $employee->first_name, 'date' => $employment->end_date?->toDateString()]));
    }

    public bool $confirmingEnd = false;

    public function changeSalary(ChangeSalary $change): void
    {
        $employee = Employee::query()->findOrFail((int) $this->editingId);
        $salary = $change($this->user(), $this->company(), $employee, $this->raise['valid_from'], $this->raise['salary'], $this->raise['reason']);

        $this->reset('raise');
        $current = Employment::query()->whereKey($salary->employment_id)->first()?->monthly_salary_ore;

        if ($current !== null) {
            $this->form['salary'] = $current->format(withCurrency: false);
        }

        $this->toast(__('Ny lön för :name från :date är sparad. Månader som redan är utbetalda rättas med retroaktiv lön i nästa lönekörning.', ['name' => $employee->first_name, 'date' => $salary->valid_from->toDateString()]));
    }

    /** Erasure on request (GDPR art. 17), within the retention of payslips. */
    public function erase(EraseEmployee $erase): void
    {
        try {
            ['mode' => $mode, 'kept_until' => $keptUntil] = $erase($this->user(), $this->company(), Employee::query()->findOrFail((int) $this->editingId));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->cancel();
        $this->toast(match ($mode) {
            'deleted' => __('Den anställda är raderad.'),
            'anonymised' => __('Den anställda är anonymiserad. Lagringstiden för lönebeskeden har gått ut.'),
            'contact_removed' => __('Kontakt- och bankuppgifter är borttagna. Namn och personnummer finns kvar till och med :date för lönebesked och AGI, enligt bokföringslagen. Radera igen efter det datumet.', ['date' => $keptUntil?->toDateString()]),
        });
    }

    public function confirmBank(int $employeeId, ConfirmBankChange $confirm): void
    {
        try {
            $employee = $confirm->confirm($this->user(), $this->company(), Employee::query()->findOrFail($employeeId));
            $this->toast(__('Det nya bankkontot för :name är bekräftat.', ['name' => $employee->first_name]));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function withdrawBank(int $employeeId, ConfirmBankChange $confirm): void
    {
        $confirm->withdraw($this->user(), $this->company(), Employee::query()->findOrFail($employeeId));
        $this->toast(__('Ändringen av bankkontot är borttagen.'));
    }

    public function save(SaveEmployee $save): void
    {
        $employee = $save($this->user(), $this->company(), $this->form, $this->editingId === null ? null : Employee::query()->findOrFail($this->editingId));

        $this->toast($this->editingId === null
            ? __(':name är tillagd som anställd :number.', ['name' => "{$employee->first_name} {$employee->last_name}", 'number' => $employee->employee_number])
            : __('Uppgifterna för :name är sparade.', ['name' => "{$employee->first_name} {$employee->last_name}"]));

        $this->cancel();
    }

    /** @var array<string, string> */
    public array $plan = ['name' => '', 'template' => 'ITP1_LIKE', 'below_percent' => '4,5', 'above_percent' => '30'];

    public bool $showPlanForm = false;

    public function updatedPlanTemplate(string $template): void
    {
        [$below, $above] = SavePensionPlan::TEMPLATES[$template] ?? [0, 0];
        $this->plan['below_percent'] = str_replace('.', ',', (string) ($below / 100));
        $this->plan['above_percent'] = str_replace('.', ',', (string) ($above / 100));
    }

    public function savePlan(SavePensionPlan $save): void
    {
        $plan = $save($this->user(), $this->company(), $this->plan);

        $this->reset('plan', 'showPlanForm');
        $this->toast(__('Pensionsplanen :name är sparad. Välj den på de anställda som ska ha den.', ['name' => $plan->name]));
    }

    public function invite(int $employeeId, InviteEmployeeToPortal $invite): void
    {
        $employee = Employee::query()->findOrFail($employeeId);

        try {
            $sent = $invite($this->user(), $this->company(), $employee);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($sent
            ? __('Inbjudan till Min sida är skickad till :email.', ['email' => $employee->email])
            : __(':name var redan medlem i företaget och är nu kopplad till sin inloggning.', ['name' => $employee->first_name]));
    }

    public function render(): View
    {
        $employees = Employee::query()
            ->with(['employments' => fn ($query) => $query->whereNull('end_date')->latest('start_date')])
            ->orderBy('employee_number')
            ->get();

        return view('payroll::livewire.employees', [
            'employees' => $employees,
            'vacation' => $this->vacation($employees),
            'canManage' => Gate::allows('manage', [Employee::class, $this->company()]),
            'editing' => $this->editingId === null ? null : Employee::query()->find($this->editingId),
            'members' => $this->showForm ? $this->company()->allUsers()->sortBy('name') : collect(),
            'pensionPlans' => PensionPlan::query()->orderBy('name')->get(),
            'endedOn' => $this->endedOn(),
            'salaryHistory' => $this->editingId === null ? collect() : SalaryChange::query()->where('employee_id', $this->editingId)->orderByDesc('valid_from')->get(),
        ]);
    }

    /**
     * Vacation balances today, or none while the vacation rules are not checked.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array{year_start: CarbonImmutable, year_end: CarbonImmutable, earned: int, taken: int, saved: int, left: int, basis_ore: int, payout_with_salary: bool}>
     */
    private function vacation(Collection $employees): array
    {
        $today = CarbonImmutable::today();

        try {
            return app(VacationLedger::class)->overview(
                $employees,
                $employees->mapWithKeys(fn (Employee $employee): array => [$employee->id => $employee->employments->first()])->filter(),
                $today,
                app(ModuleManager::class)->settings(PayrollModule::KEY, $this->company()),
                RuleSet::for($today),
                $today,
            );
        } catch (MissingPayrollRuleException) {
            return [];
        }
    }

    /** The last day of the employee's employment, once every employment has ended. */
    private function endedOn(): ?CarbonImmutable
    {
        if ($this->editingId === null) {
            return null;
        }

        $employments = Employment::query()->where('employee_id', $this->editingId)->get();

        if ($employments->isEmpty() || $employments->contains(fn (Employment $employment): bool => $employment->end_date === null)) {
            return null;
        }

        return $employments->max('end_date');
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
