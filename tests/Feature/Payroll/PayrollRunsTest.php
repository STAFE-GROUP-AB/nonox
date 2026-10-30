<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\PayrollRunList;
use App\Modules\Payroll\Livewire\PayrollRunPage;
use App\Modules\Payroll\Models\Accumulator;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
});

it('creates one run per month with the payment day moved off the weekend', function () {
    $run = $this->runs->create($this->owner, $this->company, '2026-04');

    expect($run->payment_date->toDateString())->toBe('2026-04-24')
        ->and($run->title())->toBe('Lön april 2026')
        ->and(fn () => $this->runs->create($this->owner, $this->company, '2026-04'))->toThrow(ValidationException::class, 'redan en lönekörning');
});

it('calculates, is approved by someone else and adds to the year-to-date totals', function () {
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->recordAbsence($this->owner, $run, ['employee_id' => $this->anna->id, 'type' => 'SICK', 'date_from' => '2026-04-07', 'date_to' => '2026-04-08', 'hours' => '8']);
    $this->runs->calculate($this->owner, $run);

    $payslip = Payslip::query()->sole();
    expect($run->fresh()->status)->toBe(PayrollRun::CALCULATED)
        ->and($payslip->gross_ore->ore)->toBe(3_022_769)
        ->and($payslip->tax_ore->ore)->toBe(597_900)
        ->and($payslip->net_ore->ore)->toBe(2_424_869)
        ->and($payslip->lines()->count())->toBe(4)
        ->and(fn () => $this->runs->approve($this->owner, $run->fresh()))->toThrow(ValidationException::class, 'kan därför inte attestera');

    $this->runs->approve($this->admin, $run->fresh());

    expect($run->fresh()->status)->toBe(PayrollRun::APPROVED)
        ->and(Accumulator::query()->where('key', 'gross_ytd')->sole()->value)->toBe(3_022_769)
        ->and(Accumulator::query()->where('key', 'tax_ytd')->sole()->value)->toBe(597_900);
});

it('never changes an approved run', function () {
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $run);
    $this->runs->approve($this->admin, $run->fresh());
    $payslip = Payslip::query()->sole();

    expect(fn () => $this->runs->addInput($this->owner, $run->fresh(), ['employee_id' => $this->anna->id, 'pay_code_id' => PayCode::query()->where('code', 'OVERTID')->value('id'), 'quantity' => '2']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('payslips')->where('id', $payslip->id)->update(['net_ore' => 1])))->toThrow(QueryException::class, 'approved payroll run')
        ->and(fn () => DB::transaction(fn () => DB::table('payroll_runs')->where('id', $run->id)->update(['status' => 'DRAFT'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('payroll_runs')->where('id', $run->id)->delete()))->toThrow(QueryException::class);
});

it('blocks the whole run and lists why, changing nothing: no tables or rules for 2025', function () {
    $run = $this->runs->create($this->owner, $this->company, '2025-12');

    try {
        $this->runs->calculate($this->owner, $run);
        $this->fail('The run should be blocked.');
    } catch (ValidationException $exception) {
        expect(implode(' ', $exception->errors()['run']))->toContain('Anna Kock:');
    }

    expect($run->fresh()->status)->toBe(PayrollRun::DRAFT)
        ->and(Payslip::query()->count())->toBe(0);
});

it('makes a calculated run a draft again when inputs change', function () {
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $run);
    $this->runs->addInput($this->owner, $run->fresh(), ['employee_id' => $this->anna->id, 'pay_code_id' => PayCode::query()->where('code', 'FORSKOTT')->value('id'), 'amount' => '1 000']);

    expect($run->fresh()->status)->toBe(PayrollRun::DRAFT)
        ->and(Payslip::query()->count())->toBe(0);

    $this->runs->calculate($this->owner, $run->fresh());
    expect(Payslip::query()->sole()->net_ore->ore)->toBe(2_561_900 - 100_000);
});

describe('in the browser', function () {
    it('creates a run, adds a supplement and calculates, with toasts', function () {
        Livewire\Livewire::test(PayrollRunList::class)
            ->set('period', '2026-04')
            ->call('create')
            ->assertRedirect();

        $run = PayrollRun::query()->sole();
        expect(session('toasts')[0]['message'])->toBe('Lön april 2026 är skapad med utbetalning 2026-04-24.');
        $this->get(route('payroll.runs.index'))->assertOk()->assertSee('Lön april 2026');

        Livewire\Livewire::test(PayrollRunPage::class, ['run' => $run])
            ->set('input', ['employee_id' => (string) $this->anna->id, 'pay_code_id' => (string) PayCode::query()->where('code', 'OBTILLAGG')->value('id'), 'quantity' => '', 'amount' => '850', 'note' => ''])
            ->call('addInput')
            ->assertDispatched('toast', message: 'Raden är tillagd. Beräkna lönekörningen igen.')
            ->call('calculate')
            ->assertDispatched('toast', message: 'Lönekörningen är beräknad. Kontrollera lönebeskeden och skicka den för attest.')
            ->assertSet('blockers', [])
            ->assertSee('OB-tillägg');

        $this->get(route('payroll.runs.show', $run))->assertOk()->assertSee('Timmar och tillägg');

        [$stranger] = Bookkeeping::company();
        $this->actingAs($stranger)->get(route('payroll.runs.show', $run))->assertNotFound();
    });

    it('lets someone else approve from the page', function () {
        $run = $this->runs->create($this->owner, $this->company, '2026-04');
        $this->runs->calculate($this->owner, $run);

        Livewire\Livewire::test(PayrollRunPage::class, ['run' => $run->fresh()])
            ->assertSee('Någon annan än du behöver attestera');

        $this->actingAs($this->admin);
        Livewire\Livewire::test(PayrollRunPage::class, ['run' => $run->fresh()])
            ->assertSee('Anna Kock')
            ->call('approve')
            ->assertDispatched('toast', message: 'Lön april 2026 är attesterad och kan inte längre ändras.');
    });
});

it('books a paid run as a balanced salary voucher in series L', function () {
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->recordAbsence($this->owner, $run, ['employee_id' => $this->anna->id, 'type' => 'SICK', 'date_from' => '2026-04-07', 'date_to' => '2026-04-08', 'hours' => '8']);
    $this->runs->calculate($this->owner, $run);
    $this->runs->approve($this->admin, $run->fresh());

    expect(fn () => $this->runs->post($this->owner, $run->fresh()))->toThrow(AuthorizationException::class);

    $this->runs->markPaid($this->owner, $run->fresh());
    expect(fn () => $this->runs->post($this->owner, $run->fresh()))->toThrow(ValidationException::class, 'saknar konto');

    app(AddPayrollAccounts::class)($this->owner, $this->company);
    $posted = $this->runs->post($this->owner, $run->fresh());
    $lines = [];

    foreach ($posted->voucher->load('rows.account')->rows as $row) {
        $lines[$row->account->number] = ($lines[$row->account->number] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
    }

    ksort($lines);

    expect($posted->status)->toBe(PayrollRun::POSTED)
        ->and($posted->voucher->reference())->toBe('L1')
        ->and($posted->voucher->voucher_date->toDateString())->toBe('2026-04-24')
        ->and($lines)->toBe([1930 => -2_424_869, 2710 => -597_900, 2730 => -949_754, 7010 => 3_200_000 - 295_385, 7081 => 236_308 - 118_154, 7510 => 949_754])
        ->and(array_sum($lines))->toBe(0);
});
