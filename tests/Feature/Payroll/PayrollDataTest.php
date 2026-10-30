<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->company, $this->owner);
    $this->annaUser = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->anna = Employee::create(['employee_number' => 1, 'user_id' => $this->annaUser->id, 'first_name' => 'Anna', 'last_name' => 'Kock', 'personal_number' => '199001011234', 'birth_date' => '1990-01-01', 'bank_account' => '1234567', 'notes' => 'Allergi mot nötter']);
    $this->bo = Employee::create(['employee_number' => 2, 'first_name' => 'Bo', 'last_name' => 'Servitör', 'personal_number' => '198502021234', 'birth_date' => '1985-02-02']);
});

it('encrypts personal numbers, bank details, notes and absence notes at rest', function () {
    $raw = DB::table('employees')->where('id', $this->anna->id)->first();
    $absence = Absence::create(['employee_id' => $this->anna->id, 'type' => 'SICK', 'date_from' => '2026-03-02', 'date_to' => '2026-03-03', 'note' => 'Influensa']);

    expect($raw->personal_number)->not->toContain('199001011234')
        ->and($raw->bank_account)->not->toContain('1234567')
        ->and($raw->notes)->not->toContain('nötter')
        ->and(DB::table('absences')->where('id', $absence->id)->value('note'))->not->toContain('Influensa')
        ->and($this->anna->fresh()->personal_number)->toBe('199001011234')
        ->and($absence->fresh()->note)->toBe('Influensa');
});

it('lets an employee see only themselves, and payroll staff everyone', function () {
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    CurrentCompany::set($this->company);

    expect(Gate::forUser($this->annaUser)->allows('view', $this->anna))->toBeTrue()
        ->and(Gate::forUser($this->annaUser)->allows('view', $this->bo))->toBeFalse()
        ->and(Gate::forUser($this->annaUser)->allows('viewAny', [Employee::class, $this->company]))->toBeFalse()
        ->and(Gate::forUser($payrollAdmin)->allows('view', $this->bo))->toBeTrue()
        ->and(Gate::forUser($this->owner)->allows('view', $this->bo))->toBeTrue();
});

it('keeps sensitive data from the owner but not from the payroll administrator', function () {
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    CurrentCompany::set($this->company);

    expect(Gate::forUser($this->owner)->allows('viewSensitive', $this->anna))->toBeFalse()
        ->and(Gate::forUser($payrollAdmin)->allows('viewSensitive', $this->anna))->toBeTrue();
});

it('gives an employee nothing while payroll is off', function () {
    app(ModuleManager::class)->disable(PayrollModule::KEY, $this->company, $this->owner, confirmed: true);

    expect(Gate::forUser($this->annaUser)->allows('view', $this->anna))->toBeFalse()
        ->and(Gate::forUser($this->owner)->allows('view', $this->anna))->toBeFalse();
});
