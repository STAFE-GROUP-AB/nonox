<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\SaveEmployee;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The employee register: everyone on the payroll, with a form for adding and
 * changing an employee and the current employment.
 */
class Employees extends Component
{
    use ShowsToasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless(Gate::allows('viewAny', [Employee::class, $this->company()]), 403);
    }

    public function create(): void
    {
        abort_unless(Gate::allows('manage', [Employee::class, $this->company()]), 403);

        $this->editingId = null;
        $this->form = [
            'first_name' => '', 'last_name' => '', 'personal_number' => '', 'email' => '', 'phone' => '',
            'address' => '', 'postal_code' => '', 'city' => '', 'bank_clearing' => '', 'bank_account' => '',
            'tax_residence' => 'SE', 'tax_table' => '', 'tax_column' => '1',
            'start_date' => now()->toDateString(), 'employment_type' => 'TILLSVIDARE', 'salary_form' => 'MONTHLY',
            'salary' => '', 'employment_rate' => '100', 'position_title' => '', 'cost_center' => '',
        ];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function edit(int $employeeId): void
    {
        abort_unless(Gate::allows('manage', [Employee::class, $this->company()]), 403);

        $employee = Employee::query()->findOrFail($employeeId);
        $employment = Employment::query()->with('costCenter')->where('employee_id', $employee->id)->whereNull('end_date')->latest('start_date')->first();

        $this->editingId = $employee->id;
        $this->form = array_map(fn (mixed $value): string => (string) $value, [
            ...$employee->only(['first_name', 'last_name', 'personal_number', 'email', 'phone', 'address', 'postal_code', 'city', 'bank_clearing', 'bank_account', 'tax_residence', 'tax_table', 'tax_column']),
            'start_date' => $employment?->start_date->toDateString(),
            'employment_type' => $employment === null ? 'TILLSVIDARE' : $employment->employment_type,
            'salary_form' => $employment === null ? 'MONTHLY' : $employment->salary_form,
            'salary' => ($employment?->salary_form === 'HOURLY' ? $employment->hourly_rate_ore : $employment?->monthly_salary_ore)?->format(withCurrency: false),
            'employment_rate' => $employment === null ? '100' : (string) intdiv((int) $employment->employment_rate_bp, 100),
            'position_title' => $employment?->position_title,
            'cost_center' => $employment?->costCenter?->code,
        ]);
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'editingId', 'form');
    }

    public function save(SaveEmployee $save): void
    {
        $employee = $save($this->user(), $this->company(), $this->form, $this->editingId === null ? null : Employee::query()->findOrFail($this->editingId));

        $this->toast($this->editingId === null
            ? __(':name är tillagd som anställd :number.', ['name' => "{$employee->first_name} {$employee->last_name}", 'number' => $employee->employee_number])
            : __('Uppgifterna för :name är sparade.', ['name' => "{$employee->first_name} {$employee->last_name}"]));

        $this->cancel();
    }

    public function render(): View
    {
        return view('payroll::livewire.employees', [
            'employees' => Employee::query()
                ->with(['employments' => fn ($query) => $query->whereNull('end_date')->latest('start_date')])
                ->orderBy('employee_number')
                ->get(),
            'canManage' => Gate::allows('manage', [Employee::class, $this->company()]),
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
