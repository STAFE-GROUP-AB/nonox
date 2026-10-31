<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Payroll\Actions\ConfirmBankChange;
use App\Modules\Payroll\Actions\SaveEmployee;
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Payroll::setUp($this->company, $this->owner);
    $this->first = Bookkeeping::member($this->company, 'payroll_admin');
    $this->second = Bookkeeping::member($this->company, 'payroll_admin');
    $this->employee = Payroll::employee($this->first, $this->company, ['bank_clearing' => '8327', 'bank_account' => '1234567890']);
});

function bankInput(Employee $employee, array $bank): array
{
    return [
        'first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'personal_number' => '',
        'tax_residence' => 'SE', 'tax_table' => 33, 'tax_column' => 1,
        'start_date' => '2025-01-01', 'employment_type' => 'TILLSVIDARE', 'salary_form' => 'MONTHLY', 'salary' => '32 000', 'employment_rate' => 100, 'weekly_hours' => '40',
        ...$bank,
    ];
}

it('keeps a changed salary account waiting until someone else confirms it', function () {
    $before = $this->employee->bank_account;

    app(SaveEmployee::class)($this->first, $this->company, bankInput($this->employee, ['bank_clearing' => '8327', 'bank_account' => '9998887776']), $this->employee);
    $employee = $this->employee->fresh();

    expect($employee->bank_account)->toBe($before)
        ->and($employee->hasPendingBankChange())->toBeTrue()
        ->and(fn () => app(ConfirmBankChange::class)->confirm($this->first, $this->company, $employee))->toThrow(ValidationException::class);

    app(ConfirmBankChange::class)->confirm($this->second, $this->company, $employee);

    expect($employee->fresh()->bank_account)->toBe('9998887776')
        ->and($employee->fresh()->hasPendingBankChange())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'payroll.bank_change_confirmed')->value('payload'))->toMatchArray(['new' => '****7776']);
});

it('keeps the personal number and account out of the page state, and an empty field keeps them', function () {
    Livewire::actingAs($this->first)->test(Employees::class)
        ->call('edit', $this->employee->id)
        ->assertSet('form.personal_number', '')
        ->assertSet('form.bank_account', '')
        ->call('save');

    expect($this->employee->fresh()->personal_number)->toBe($this->employee->personal_number)
        ->and($this->employee->fresh()->hasPendingBankChange())->toBeFalse();
});
