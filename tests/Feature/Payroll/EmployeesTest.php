<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleDisabledException;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\SaveEmployee;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Support\Luhn;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/** A made-up but valid personal number for a date. */
function testPersonalNumber(string $date, string $serial = '123'): string
{
    $tenDigits = substr(str_replace('-', '', $date), 2).$serial;

    return substr(str_replace('-', '', $date), 0, 2).$tenDigits.Luhn::checkDigit($tenDigits);
}

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->company, $this->owner);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->input = [
        'first_name' => 'Anna', 'last_name' => 'Kock', 'personal_number' => testPersonalNumber('1990-05-17'),
        'bank_clearing' => '8327', 'bank_account' => '9123456789', 'tax_residence' => 'SE', 'tax_table' => 33, 'tax_column' => 1,
        'start_date' => '2026-03-01', 'employment_type' => 'TILLSVIDARE', 'salary_form' => 'MONTHLY', 'salary' => '32 000', 'employment_rate' => 100,
        'position_title' => 'Kock',
    ];
});

it('reads twelve-digit personal and coordination numbers and refuses the rest', function () {
    expect(PersonalNumber::tryFrom(testPersonalNumber('1990-05-17'))?->birthDate->toDateString())->toBe('1990-05-17')
        ->and(PersonalNumber::tryFrom(testPersonalNumber('1990-05-77'))?->birthDate->toDateString())->toBe('1990-05-17')
        ->and(PersonalNumber::tryFrom(testPersonalNumber('1990-05-77'))?->coordination)->toBeTrue()
        ->and(PersonalNumber::tryFrom(substr(testPersonalNumber('1990-05-17'), 2)))->toBeNull()
        ->and(PersonalNumber::tryFrom('199005171230'))->toBeNull()
        ->and(PersonalNumber::masked('199005171234'))->toBe('19900517-XXXX');
});

it('adds an employee with an employment, numbered, and never logs the personal number', function () {
    $employee = app(SaveEmployee::class)($this->owner, $this->company, $this->input);
    $employment = $employee->employments()->sole();

    expect($employee->employee_number)->toBe(1)
        ->and($employee->birth_date->toDateString())->toBe('1990-05-17')
        ->and($employment->monthly_salary_ore->ore)->toBe(3_200_000)
        ->and($employment->employment_rate_bp)->toBe(10000);

    $log = json_encode(AuditLog::query()->where('action', 'payroll.employee_created')->sole()->payload);
    expect($log)->not->toContain(substr($this->input['personal_number'], 0, 8))->not->toContain('9123456789')->toContain('personal_number');
});

it('requires the tax table and column for someone living in Sweden', function () {
    expect(fn () => app(SaveEmployee::class)($this->owner, $this->company, [...$this->input, 'tax_table' => null]))
        ->toThrow(ValidationException::class, 'skattebesked');

    $sink = app(SaveEmployee::class)($this->owner, $this->company, [...$this->input, 'tax_residence' => 'NON_RESIDENT_SINK', 'tax_table' => null, 'tax_column' => null]);
    expect($sink->tax_residence)->toBe('NON_RESIDENT_SINK');
});

it('is only for employees:manage, and only while payroll is on', function () {
    expect(fn () => app(SaveEmployee::class)(Bookkeeping::member($this->company, 'accountant'), $this->company, $this->input))->toThrow(AuthorizationException::class);

    app(ModuleManager::class)->disable(PayrollModule::KEY, $this->company, $this->owner, confirmed: true);
    expect(fn () => app(SaveEmployee::class)($this->owner, $this->company, $this->input))->toThrow(ModuleDisabledException::class);
});

it('lists employees with masked personal numbers and edits them, with toasts', function () {
    Livewire::test(Employees::class)
        ->call('create')
        ->set('form', [...array_map('strval', $this->input), 'email' => '', 'phone' => '', 'address' => '', 'postal_code' => '', 'city' => '', 'cost_center' => ''])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Anna Kock är tillagd som anställd 1.');

    $employee = Employee::query()->sole();
    $this->get(route('payroll.employees'))->assertOk()->assertSee('19900517-XXXX')->assertDontSee($this->input['personal_number']);

    Livewire::test(Employees::class)
        ->call('edit', $employee->id)
        ->assertSet('form.salary', Money::ofOre(3_200_000)->format(withCurrency: false))
        ->set('form.salary', '33 500')
        ->call('save')
        ->assertDispatched('toast', message: 'Uppgifterna för Anna Kock är sparade.');

    expect($employee->employments()->sole()->monthly_salary_ore->ore)->toBe(3_350_000);
});
