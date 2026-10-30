<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\ChangeSalary;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Actions\SaveEmployee;
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\SalaryChange;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Payroll::employee($this->owner, $this->company);
    $this->runs = app(PayrollRuns::class);
    $this->change = app(ChangeSalary::class);
});

function retroPayRun(string $period): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $run = $runs->create(test()->owner, test()->company, $period);
    $runs->calculate(test()->owner, $run);

    return $run->fresh();
}

/** @return array<string, int> */
function retroLinesOf(PayrollRun $run): array
{
    $codes = PayCode::query()->pluck('code', 'id');

    return PayslipLine::query()->whereIn('payslip_id', Payslip::query()->where('payroll_run_id', $run->id)->select('id'))->get()
        ->groupBy(fn (PayslipLine $line): string => (string) $codes[$line->pay_code_id])
        ->map(fn ($lines): int => $lines->sum(fn (PayslipLine $line): int => $line->amount_ore->ore))
        ->all();
}

it('pays the difference for a month already paid at the old salary, once, as a one-off', function () {
    $march = retroPayRun('2026-03');
    $this->runs->approve($this->admin, $march);

    $this->change->__invoke($this->owner, $this->company, $this->anna, '2026-03-01', '34 000', 'Lönerevision 2026');

    expect(Employment::query()->sole()->monthly_salary_ore->ore)->toBe(3_400_000)
        ->and(SalaryChange::query()->orderBy('valid_from')->pluck('valid_from')->map->toDateString()->all())->toBe(['2025-01-01', '2026-03-01']);

    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    $april = retroPayRun('2026-04');
    $retro = PayslipLine::query()->whereIn('payslip_id', Payslip::query()->where('payroll_run_id', $april->id)->select('id'))
        ->where('pay_code_id', PayCode::query()->where('code', 'RETROLON')->value('id'))->sole();

    expect(retroLinesOf($april)['MANADSLON'])->toBe(3_400_000)
        ->and($retro->amount_ore->ore)->toBe(200_000)
        ->and($retro->tax_effect)->toBe('ONE_OFF')
        ->and($retro->explanation['retro_period'])->toBe('2026-03')
        ->and($retro->description)->toBe('Retroaktiv lön för 2026-03');

    $this->runs->approve($this->admin, $april);
    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00:00'));

    expect(retroLinesOf(retroPayRun('2026-05')))->not->toHaveKey('RETROLON');
});

it('takes a lower salary back the same way', function () {
    $this->runs->approve($this->admin, retroPayRun('2026-03'));
    $this->change->__invoke($this->owner, $this->company, $this->anna, '2026-03-01', '31 000');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));

    expect(retroLinesOf(retroPayRun('2026-04'))['RETROLON'])->toBe(-100_000);
});

it('only takes a new salary from the first of a month after the employment began', function () {
    expect(fn () => $this->change->__invoke($this->owner, $this->company, $this->anna, '2026-03-15', '34 000'))->toThrow(ValidationException::class, 'första dagen i en månad')
        ->and(fn () => $this->change->__invoke($this->owner, $this->company, $this->anna, '2025-01-01', '34 000'))->toThrow(ValidationException::class, 'första månad')
        ->and(fn () => $this->change->__invoke($this->owner, $this->company, $this->anna, '2026-04-01', 'mycket'))->toThrow(ValidationException::class, 'går inte att läsa');
});

it('keeps a future salary out of the months before it', function () {
    $this->change->__invoke($this->owner, $this->company, $this->anna, '2026-05-01', '35 000');

    expect(Employment::query()->sole()->monthly_salary_ore->ore)->toBe(3_200_000)
        ->and(retroLinesOf(retroPayRun('2026-03'))['MANADSLON'])->toBe(3_200_000);
});

it('records a salary edited on the employee card as a new salary from this month once there is a history', function () {
    $this->change->__invoke($this->owner, $this->company, $this->anna, '2026-02-01', '33 000');

    app(SaveEmployee::class)($this->owner, $this->company, [
        'first_name' => 'Anna', 'last_name' => 'Kock', 'personal_number' => $this->anna->personal_number,
        'tax_residence' => 'SE', 'tax_table' => 33, 'tax_column' => 1,
        'start_date' => '2025-01-01', 'employment_type' => 'TILLSVIDARE', 'salary_form' => 'MONTHLY', 'salary' => '33 800', 'employment_rate' => 100, 'weekly_hours' => '40',
    ], $this->anna);

    expect(SalaryChange::query()->whereDate('valid_from', '2026-03-01')->sole()->monthly_salary_ore->ore)->toBe(3_380_000);
});

it('takes a new salary on the employee page', function () {
    Livewire\Livewire::test(Employees::class)
        ->call('edit', $this->anna->id)
        ->assertSee('Lönerevision')
        ->set('raise.valid_from', '2026-02-01')
        ->set('raise.salary', '33 000')
        ->call('changeSalary')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertSet('form.salary', fn (string $salary): bool => preg_replace('/\D/u', '', $salary) === '3300000')
        ->assertSee('2026-02-01');
});
