<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\SickPayPeriod;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * The karensavdrag per sjuklöneperiod across payroll runs (sjuklönelagen
 * 6–7 §§, prop. 2017/18:96 s. 68–69; enligt AI-utredning 2026-11-02,
 * juristgranskning återstår): each run stores what it deducted in each
 * sick period (sick_pay_periods), frozen with the approved run, and the
 * next run takes the rest of the karensavdrag from the same period's sick
 * pay. Rule values are test data (Tests\Support\Payroll::RULES).
 *
 * Anna: 32 000 kr a month, 40 h a week. Hour value 32 000 × 12 / 52 / 40 =
 * 184,62 kr; karensavdrag 20 % × 80 % × 32 000 × 12 / 52 = 1 181,54 kr.
 */

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

/** @return list<int> the karensavdrag lines of the employee's payslip in the run */
function karensLines(PayrollRun $run): array
{
    return Payslip::query()->with('lines')->where('payroll_run_id', $run->id)->sole()->lines
        ->filter(fn ($line): bool => $line->description === 'Karensavdrag')
        ->map(fn ($line): int => $line->amount_ore->ore)
        ->values()
        ->all();
}

it('carries the rest of the karensavdrag into the next run after reinfection, and freezes what was stored', function () {
    $april = $this->runs->create($this->owner, $this->company, '2026-04');
    // One sick hour on 30 April: sick pay 184,62 × 80 % = 147,69 kr, all of it goes to the karensavdrag.
    $this->runs->recordAbsence($this->owner, $april, ['employee_id' => $this->anna->id, 'type' => 'SICK', 'date_from' => '2026-04-30', 'date_to' => '2026-04-30', 'hours' => '1']);
    $this->runs->calculate($this->owner, $april);
    $payslip = Payslip::query()->where('payroll_run_id', $april->id)->sole();

    expect(karensLines($april))->toBe([-14_769])
        ->and($payslip->warnings)->toContain('Hela karensavdraget för sjukperioden från 2026-04-30 rymdes inte i sjuklönen. Resten, 1 033,85 kr, dras från sjuklönen om sjukperioden fortsätter i nästa lönekörning.');

    $this->runs->approve($this->admin, $april->fresh());
    $stored = SickPayPeriod::query()->sole();

    expect($stored->period_start->toDateString())->toBe('2026-04-30')
        ->and($stored->karens_full_ore)->toBe(118_154)
        ->and($stored->karens_deducted_ore)->toBe(14_769)
        ->and(fn () => DB::transaction(fn () => DB::table('sick_pay_periods')->where('id', $stored->id)->update(['karens_deducted_ore' => 0])))->toThrow(QueryException::class, 'approved payroll run')
        ->and(fn () => DB::transaction(fn () => DB::table('sick_pay_periods')->where('id', $stored->id)->delete()))->toThrow(QueryException::class, 'approved payroll run');

    // Sick again on Monday 4 May, within five days: the same sick period, no new karensavdrag, but the rest of April's.
    $may = $this->runs->create($this->owner, $this->company, '2026-05');
    $this->runs->recordAbsence($this->owner, $may, ['employee_id' => $this->anna->id, 'type' => 'SICK', 'date_from' => '2026-05-04', 'date_to' => '2026-05-04', 'hours' => '8']);
    $this->runs->calculate($this->owner, $may);
    $line = Payslip::query()->with('lines')->where('payroll_run_id', $may->id)->sole()->lines->firstWhere('description', 'Karensavdrag');

    expect(karensLines($may))->toBe([-103_385])
        ->and($line->explanation['period_start'])->toBe('2026-04-30')
        ->and($line->explanation['carried'])->toBeTrue()
        ->and(SickPayPeriod::query()->where('payroll_run_id', $may->id)->sole()->karens_deducted_ore)->toBe(103_385);
});

it('reads only approved runs: a sick period from a run that is not approved is said to be taken, not silently', function () {
    $april = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->recordAbsence($this->owner, $april, ['employee_id' => $this->anna->id, 'type' => 'SICK', 'date_from' => '2026-04-30', 'date_to' => '2026-04-30', 'hours' => '1']);
    $this->runs->calculate($this->owner, $april);

    $may = $this->runs->create($this->owner, $this->company, '2026-05');
    $this->runs->recordAbsence($this->owner, $may, ['employee_id' => $this->anna->id, 'type' => 'SICK', 'date_from' => '2026-05-04', 'date_to' => '2026-05-04', 'hours' => '8']);
    $this->runs->calculate($this->owner, $may);

    expect(karensLines($may))->toBe([])
        ->and(Payslip::query()->where('payroll_run_id', $may->id)->sole()->warnings)->toContain('Sjukperioden från 2026-04-30 finns inte i någon attesterad lönekörning. Karensavdraget för den räknas som redan gjort.');
});

it('replaces the stored sick periods when a run is calculated again', function () {
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->recordAbsence($this->owner, $run, ['employee_id' => $this->anna->id, 'type' => 'SICK', 'date_from' => '2026-04-07', 'date_to' => '2026-04-08', 'hours' => '8']);
    $this->runs->calculate($this->owner, $run);
    $this->runs->calculate($this->owner, $run->fresh());

    expect(SickPayPeriod::query()->where('payroll_run_id', $run->id)->count())->toBe(1)
        ->and(SickPayPeriod::query()->sole()->karens_deducted_ore)->toBe(118_154);
});
