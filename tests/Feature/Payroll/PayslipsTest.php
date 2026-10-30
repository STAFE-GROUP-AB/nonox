<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\MyPayslips;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    $this->worker = Bookkeeping::member($this->company, 'employee');
    $this->colleague = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->anna = Payroll::employee($this->owner, $this->company, ['user_id' => $this->worker->id]);
    $this->runs = app(PayrollRuns::class);
    $this->run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $this->run);
    $this->payslip = Payslip::query()->sole();
});

it('gives payroll staff the payslip as a PDF', function () {
    $this->actingAs($this->owner)->get(route('payroll.payslips.pdf', $this->payslip))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('shows the employee their own payslip only once the run is approved', function () {
    $this->actingAs($this->worker)->get(route('payroll.payslips.pdf', $this->payslip))->assertNotFound();

    $this->runs->approve($this->admin, $this->run->fresh());

    $this->actingAs($this->worker)->get(route('payroll.payslips.pdf', $this->payslip))->assertOk();
    $this->actingAs($this->colleague)->get(route('payroll.payslips.pdf', $this->payslip))->assertNotFound();
});

it('answers 404 for another company\'s payslip', function () {
    [$stranger] = Bookkeeping::company();

    $this->actingAs($stranger)->get(route('payroll.payslips.pdf', $this->payslip))->assertNotFound();
});

it('lists only the employee\'s own payslips from approved runs', function () {
    CurrentCompany::set($this->company);
    $this->actingAs($this->worker);

    Livewire::test(MyPayslips::class)->assertSee('Inga lönebesked');

    $this->runs->approve($this->admin, $this->run->fresh());

    Livewire::test(MyPayslips::class)->assertSee('Lön april 2026');

    $this->actingAs($this->colleague);
    Livewire::test(MyPayslips::class)->assertDontSee('april 2026');
});

it('links a login to one employee only, and only a member of the company', function () {
    [$stranger] = Bookkeeping::company();

    expect(fn () => Payroll::employee($this->owner, $this->company, ['user_id' => $this->worker->id, 'personal_number' => '199001011239']))
        ->toThrow(ValidationException::class, 'redan till en annan anställd')
        ->and(fn () => Payroll::employee($this->owner, $this->company, ['user_id' => $stranger->id, 'personal_number' => '199001011239']))
        ->toThrow(ValidationException::class, 'inte medlem');
});
