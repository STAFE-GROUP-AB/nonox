<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Actions\SavePensionPlan;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Payroll::employee($this->owner, $this->company);
    $this->runs = app(PayrollRuns::class);
    $this->run = $this->runs->create($this->owner, $this->company, '2026-04');
});

/** Adds an input by pay code and returns the run's payslip lines by code after calculating. */
function payWith(array $inputs): array
{
    $runs = test()->runs;

    foreach ($inputs as [$code, $values]) {
        $runs->addInput(test()->owner, test()->run, ['employee_id' => test()->anna->id, 'pay_code_id' => PayCode::query()->where('code', $code)->value('id'), ...$values]);
    }

    $runs->calculate(test()->owner, test()->run);
    $payslip = Payslip::query()->with('lines')->sole();

    return [$payslip, $payslip->lines->groupBy(fn ($line) => PayCode::query()->find($line->pay_code_id)?->code)];
}

it('taxes staff meals at Skatteverket\'s value without paying them out, less what the employee pays', function () {
    [$plain] = (function () {
        $this->runs->calculate($this->owner, $this->run);
        $payslip = Payslip::query()->sole();
        $values = [$payslip->gross_ore->ore, $payslip->net_ore->ore, $payslip->employer_contribution_ore->ore];
        $this->runs->reopen($this->owner, $this->run->fresh());

        return [$values];
    })->call($this);

    [$payslip, $lines] = payWith([['KOSTFORMAN_LUNCH', ['quantity' => '10']], ['FORMAN_EGENBETALNING', ['amount' => '300']]]);

    // 10 lunches at 124 kr = 1 240 kr benefit, lowered by the 300 kr the employee paid.
    expect($lines['KOSTFORMAN_LUNCH']->sole()->amount_ore->ore)->toBe(124_000)
        ->and($lines['OVRIG_FORMAN']->sole()->amount_ore->ore)->toBe(-30_000)
        ->and($lines['FORMAN_EGENBETALNING']->sole()->amount_ore->ore)->toBe(-30_000)
        ->and($payslip->gross_ore->ore - $plain[0])->toBe(94_000)
        ->and($payslip->employer_contribution_ore->ore)->toBeGreaterThan($plain[2])
        ->and($payslip->net_ore->ore)->toBeLessThan($plain[1] - 30_000);
});

it('pays mileage and per diem tax-free up to the rule and taxes the rest', function () {
    [$payslip, $lines] = payWith([['MILERSATTNING', ['quantity' => '100', 'amount' => null]], ['TRAKTAMENTE_HEL', ['quantity' => '2']]]);

    expect($lines['MILERSATTNING']->sole()->amount_ore->ore)->toBe(250_000)
        ->and($lines['MILERSATTNING']->sole()->tax_effect)->toBe('NET')
        ->and($lines->has('MIL_SKATTEPLIKTIG'))->toBeFalse()
        ->and($lines['TRAKTAMENTE_HEL']->sole()->amount_ore->ore)->toBe(60_000);
});

it('splits mileage paid above the tax-free 25 kr per mil', function () {
    $code = PayCode::query()->where('code', 'MILERSATTNING')->sole();
    $this->runs->addInput($this->owner, $this->run, ['employee_id' => $this->anna->id, 'pay_code_id' => $code->id, 'quantity' => '100']);
    PayrollInput::query()->update(['rate_ore' => 3_000]);
    $this->runs->calculate($this->owner, $this->run);
    $lines = Payslip::query()->with('lines')->sole()->lines->keyBy(fn ($line) => PayCode::query()->find($line->pay_code_id)?->code);

    expect($lines['MILERSATTNING']->amount_ore->ore)->toBe(250_000)
        ->and($lines['MIL_SKATTEPLIKTIG']->amount_ore->ore)->toBe(50_000)
        ->and($lines['MIL_SKATTEPLIKTIG']->tax_effect)->toBe('TABLE');
});

it('adds the pension premium and special payroll tax as the employer\'s cost and books them', function () {
    $plan = app(SavePensionPlan::class)($this->owner, $this->company, ['name' => 'Som ITP1', 'template' => 'ITP1_LIKE', 'below_percent' => '4,5', 'above_percent' => '30']);
    Employment::query()->update(['pension_plan_id' => $plan->id]);

    [$payslip, $lines] = payWith([]);

    // 4.5 % of 32 000 kr, all below 7.5 × 83 400 / 12 = 52 125 kr; special payroll tax 24.26 % on it.
    expect($lines['TJANSTEPENSION']->sole()->amount_ore->ore)->toBe(144_000)
        ->and($lines['SARSKILD_LONESKATT']->sole()->amount_ore->ore)->toBe(34_934)
        ->and($payslip->gross_ore->ore)->toBe(3_200_000);

    app(AddPayrollAccounts::class)($this->owner, $this->company);
    $this->runs->approve($this->admin, $this->run->fresh());
    $this->runs->markPaid($this->owner, $this->run->fresh());
    $rows = $this->runs->post($this->owner, $this->run->fresh())->voucher->load('rows.account')->rows;
    $by = fn (int $account): int => $rows->filter(fn ($row) => $row->account->number === $account)->sum(fn ($row) => $row->debit_ore->ore - $row->credit_ore->ore);

    expect($by(7410))->toBe(144_000)
        ->and($by(2740))->toBe(-144_000)
        ->and($by(7530))->toBe(34_934)
        ->and($by(2514))->toBe(-34_934)
        ->and($rows->sum(fn ($row) => $row->debit_ore->ore - $row->credit_ore->ore))->toBe(0);
});

it('books a benefit without a cost of its own and stays balanced', function () {
    payWith([['KOSTFORMAN_LUNCH', ['quantity' => '5']], ['UTLAGG', ['amount' => '450']]]);
    app(AddPayrollAccounts::class)($this->owner, $this->company);
    $this->runs->approve($this->admin, $this->run->fresh());
    $this->runs->markPaid($this->owner, $this->run->fresh());
    $rows = $this->runs->post($this->owner, $this->run->fresh())->voucher->load('rows.account')->rows;

    expect($rows->sum(fn ($row) => $row->debit_ore->ore - $row->credit_ore->ore))->toBe(0)
        ->and($rows->pluck('description'))->not->toContain('Kostförmån, lunch eller middag')
        ->and($rows->filter(fn ($row) => $row->account->number === 2890)->sum(fn ($row) => $row->debit_ore->ore))->toBe(45_000);
    expect(PayrollRun::query()->sole()->status)->toBe(PayrollRun::POSTED);
});
