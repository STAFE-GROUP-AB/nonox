<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\EndEmployment;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Payroll::employee($this->owner, $this->company);
    $this->runs = app(PayrollRuns::class);
    $this->end = app(EndEmployment::class);
});

function finalLines(PayrollRun $run): Collection
{
    $codes = PayCode::query()->pluck('code', 'id');

    return PayslipLine::query()->whereIn('payslip_id', Payslip::query()->where('payroll_run_id', $run->id)->select('id'))->get()
        ->keyBy(fn (PayslipLine $line): string => (string) $codes[$line->pay_code_id]);
}

it('pays salary to the last day and semesterersättning once in the final month', function () {
    $this->end->__invoke($this->owner, $this->company, $this->anna, '2026-06-15', 'Egen uppsägning');

    $june = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->calculate($this->owner, $june);
    $lines = finalLines($june);
    $payout = $lines['SEMESTERERSATTNING'];

    expect(Employee::query()->sole()->status)->toBe('TERMINATED')
        ->and($lines['MANADSLON']->amount_ore->ore)->toBeLessThan(3_200_000)
        ->and($payout->amount_ore->ore)->toBeGreaterThan(0)
        ->and($payout->tax_effect)->toBe('ONE_OFF')
        ->and($payout->explanation['termination'])->toBeTrue();

    $this->runs->approve($this->admin, $june->fresh());

    $this->travelTo(CarbonImmutable::parse('2026-07-10 10:00:00'));
    $july = $this->runs->create($this->owner, $this->company, '2026-07');

    expect(fn () => $this->runs->calculate($this->owner, $july))->toThrow(ValidationException::class, 'Ingen är anställd');
});

it('does not end an employment in a month that is already approved', function () {
    $june = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->calculate($this->owner, $june);
    $this->runs->approve($this->admin, $june->fresh());

    expect(fn () => $this->end->__invoke($this->owner, $this->company, $this->anna, '2026-06-20'))->toThrow(ValidationException::class, 'redan attesterad')
        ->and(fn () => $this->end->__invoke($this->owner, $this->company, $this->anna, '2024-12-31'))->toThrow(ValidationException::class, 'före anställningens första dag');
});

it('exports the data for an arbetsgivarintyg', function () {
    $june = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->calculate($this->owner, $june);
    $this->runs->approve($this->admin, $june->fresh());

    $csv = $this->get(route('payroll.employees.certificate', $this->anna))->assertOk()->streamedContent();

    expect($csv)->toContain('Anna Kock')
        ->and($csv)->toContain('Bruttolön')
        ->and($csv)->toContain('2026-06;32000,00');

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->actingAs($bookkeeper)->get(route('payroll.employees.certificate', $this->anna))->assertNotFound();
});

it('ends the employment from the employee page after a confirmation', function () {
    Livewire::test(Employees::class)
        ->call('edit', $this->anna->id)
        ->assertSee('Avsluta anställningen')
        ->set('ending.end_date', '2026-06-30')
        ->set('confirmingEnd', true)
        ->assertSee('Ja, avsluta anställningen')
        ->call('endEmployment')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertSee('Anställningen slutade 2026-06-30.');
});
