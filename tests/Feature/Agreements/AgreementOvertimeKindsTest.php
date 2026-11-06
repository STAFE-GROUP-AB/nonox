<?php

declare(strict_types=1);

use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\CompTimeEntry;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Planning\Actions\AttestPeriod;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\ReopenAttestation;
use App\Modules\Planning\Actions\SaveActualTime;
use App\Modules\Planning\Actions\SendToPayroll;
use App\Modules\Planning\Livewire\AttestPage;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\PlanningModule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;
use Tests\Support\Planning;

/*
 * H.1 end to end: a published week, the actual time, attest, the week's
 * overtime split by Gröna Riks' kinds (§ 12 p. 1.2: a) the two first hours
 * adjoining, b) the rest adjoining, c) not adjoining) into time reports on
 * the agreement's codes, priced by the payroll run, or saved as comp time
 * with the factor per code. Agreement values come from the shipped data
 * file and are verified only inside the test.
 */

function kindsFeatureAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Europe/Stockholm');
}

function kindsFeatureAgreement(Employee $employee, array $terms = [], string $key = 'visita-hrf-grona-riks', string $group = '2'): void
{
    $owner = test()->owner;
    $company = test()->company;
    app(ModuleManager::class)->enable(AgreementsModule::KEY, $company, $owner);
    $setup = app(AgreementSetup::class);
    $binding = $setup->saveBinding($owner, $company, ['agreement_key' => $key, 'variant' => 'MEMBER', 'bound_from' => '2025-01-01']);
    $setup->saveEmployment($owner, $company, Employment::query()->where('employee_id', $employee->id)->sole(), ['collective_agreement_id' => $binding->id, 'agreement_group' => $group, ...$terms]);
    AgreementRuleVersion::query()->where('agreement_key', $key)->where('variant', 'MEMBER')->sole()->forceFill(['verified_by' => 'Granskare', 'verified_at' => now()])->save();
}

/** A week from $monday, Monday to Friday 08–17 with an hour's break, published, with the given days worked to other times. */
function kindsFeatureWeekFrom(Employee $employee, string $monday, array $worked): void
{
    $owner = test()->owner;
    $company = test()->company;

    for ($i = 0; $i < 5; $i++) {
        Planning::shift($owner, $company, ['employee_id' => $employee->id, 'date' => kindsFeatureAt($monday)->addDays($i)->toDateString(), 'start' => '08:00', 'end' => '17:00', 'break_minutes' => 60]);
    }

    app(PublishPeriod::class)($owner, $company, kindsFeatureAt($monday), kindsFeatureAt($monday)->addDays(6));

    foreach ($worked as $date => [$start, $end]) {
        app(SaveActualTime::class)($owner, $company, ['employee_id' => $employee->id, 'date' => $date, 'intervals' => [['start' => $start, 'end' => $end, 'break_minutes' => 60]]]);
    }
}

/** @return list<string> "code hours priced_on" of the approved time reports, sorted */
function kindsFeatureReports(): array
{
    $codes = PayCode::query()->pluck('code', 'id');

    return TimeReport::query()->get()->map(fn (TimeReport $report): string => trim($codes[$report->pay_code_id].' '.$report->hours.' '.$report->priced_on?->toDateString()))->sort()->values()->all();
}

/** Monday 2026-11-09 to Friday 08–17 with an hour's break; Monday to 20:30 and Saturday 10–12 worked on top. */
function kindsFeatureWeek(Employee $employee): void
{
    $owner = test()->owner;
    $company = test()->company;

    for ($i = 0; $i < 5; $i++) {
        Planning::shift($owner, $company, ['employee_id' => $employee->id, 'date' => kindsFeatureAt('2026-11-09')->addDays($i)->toDateString(), 'start' => '08:00', 'end' => '17:00', 'break_minutes' => 60]);
    }

    app(PublishPeriod::class)($owner, $company, kindsFeatureAt('2026-11-09'), kindsFeatureAt('2026-11-15'));
    app(SaveActualTime::class)($owner, $company, ['employee_id' => $employee->id, 'date' => '2026-11-09', 'intervals' => [['start' => '08:00', 'end' => '20:30', 'break_minutes' => 60]]]);
    app(SaveActualTime::class)($owner, $company, ['employee_id' => $employee->id, 'date' => '2026-11-14', 'intervals' => [['start' => '10:00', 'end' => '12:00', 'break_minutes' => 0]]]);
}

beforeEach(function () {
    $this->travelTo(kindsFeatureAt('2026-11-25 10:00:00'));
    Notification::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    Payroll::agreementPremiums();
    $this->actingAs($this->owner);
    $this->bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234', ['start_date' => '2026-01-01']);
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $this->company, $this->owner, ['overtime_pay_code' => 'GR_OT_A']);
    $this->from = kindsFeatureAt('2026-11-01');
    $this->to = kindsFeatureAt('2026-11-30');
});

it('sends a week\'s overtime on Gröna Riks\' kinds and the run prices each at its own rate', function () {
    kindsFeatureAgreement($this->bertil);
    kindsFeatureWeek($this->bertil);

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);

    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->assertSee('Veckan: 120 min övertid, två första timmarna i anslutning enligt Gröna Riks (GR#§ 12 p. 1.2 s. 19; p. 2.2 s. 20) skickas med den här dagen (GR_OT_A).')
        ->assertSee('Veckan: 120 min övertid, inte i anslutning enligt Gröna Riks (GR#§ 12 p. 1.2 s. 19; p. 2.2 s. 20) skickas med den här dagen (GR_OT_C).');

    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);
    $codes = PayCode::query()->pluck('code', 'id');

    // Monday 17:00–20:30: 2 h a) and 1,5 h b). Saturday 10–12, a day off: 2 h c).
    expect(TimeReport::query()->orderBy('id')->get()->filter(fn (TimeReport $report): bool => str_starts_with($codes[$report->pay_code_id], 'GR_OT'))->map(fn (TimeReport $report): string => $codes[$report->pay_code_id].' '.$report->hours)->sort()->values()->all())
        ->toBe(['GR_OT_A 2.00', 'GR_OT_B 1.50', 'GR_OT_C 2.00']);

    $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
    app(PayrollRuns::class)->calculate($this->owner, $run);
    $lines = Payslip::query()->with('lines')->where('payroll_run_id', $run->id)->where('employee_id', $this->bertil->id)->sole()->lines->keyBy(fn ($line) => $codes[$line->pay_code_id]);

    // 32 000 kr / 173 = 184,97110 kr an hour (GR löneavtal § 3). From 2026-04-01: a) 135 % = 249,71 kr, b) 170 % = 314,45 kr, c) 190 % = 351,45 kr.
    expect($lines['GR_OT_A']->rate_ore->ore)->toBe(24_971)
        ->and($lines['GR_OT_A']->amount_ore->ore)->toBe(49_942)
        ->and($lines['GR_OT_B']->rate_ore->ore)->toBe(31_445)
        ->and($lines['GR_OT_B']->amount_ore->ore)->toBe(47_168)
        ->and($lines['GR_OT_C']->rate_ore->ore)->toBe(35_145)
        ->and($lines['GR_OT_C']->amount_ore->ore)->toBe(70_290);
});

it('saves the kinds as comp time with the agreement\'s factor per code', function () {
    kindsFeatureAgreement($this->bertil, ['overtime_compensation' => 'COMP_TIME']);
    kindsFeatureWeek($this->bertil);

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    // a) 120 min × 1,5 = 180, b) 90 min × 1,5 = 135, c) 120 min × 2 = 240 (GR § 12 p. 1.2): 555 min.
    expect(CompTimeEntry::query()->orderBy('pay_code')->get()->map(fn (CompTimeEntry $entry): string => "{$entry->pay_code} {$entry->worked_minutes} {$entry->factor} {$entry->minutes}")->all())
        ->toBe(['GR_OT_A 120 1.5 180', 'GR_OT_B 90 1.5 135', 'GR_OT_C 120 2 240'])
        ->and(TimeReport::query()->pluck('pay_code_id')->map(fn (int $id): string => (string) PayCode::query()->whereKey($id)->value('code'))->all())->toBe(['GR_OB']);
});

it('keeps a company code of its own for the whole week, as before', function () {
    kindsFeatureAgreement($this->bertil);
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $this->company, $this->owner, ['overtime_pay_code' => 'OVERTID']);
    kindsFeatureWeek($this->bertil);

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);
    $codes = PayCode::query()->pluck('code', 'id');

    expect(TimeReport::query()->get()->filter(fn (TimeReport $report): bool => $codes[$report->pay_code_id] === 'OVERTID')->map(fn (TimeReport $report): string => $report->hours)->values()->all())->toBe(['5.50']);
});

it('prices overtime on the day it was worked when the week crosses the rate change on 1 April 2026', function () {
    $this->travelTo(kindsFeatureAt('2026-04-08 10:00:00'));
    kindsFeatureAgreement($this->bertil);
    // Tuesday 31 March and Wednesday 1 April each an hour after the shift: Gröna Riks a), 45 % until 31 March, 35 % from 1 April (§ 12 p. 1.2 s. 19).
    kindsFeatureWeekFrom($this->bertil, '2026-03-30', ['2026-03-31' => ['08:00', '18:00'], '2026-04-01' => ['08:00', '18:00']]);

    app(AttestPeriod::class)($this->owner, $this->company, kindsFeatureAt('2026-03-30'), kindsFeatureAt('2026-04-05'));
    app(SendToPayroll::class)($this->owner, $this->company, kindsFeatureAt('2026-03-30'), kindsFeatureAt('2026-04-05'));

    // Both reports are paid with Sunday 5 April, each priced on the day worked. (Good Friday 3 April gives OB on the shift.)
    expect(array_values(array_filter(kindsFeatureReports(), fn (string $report): bool => str_starts_with($report, 'GR_OT'))))->toBe(['GR_OT_A 1.00 2026-03-31', 'GR_OT_A 1.00 2026-04-01']);

    $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-04');
    app(PayrollRuns::class)->calculate($this->owner, $run);
    $codes = PayCode::query()->pluck('code', 'id');
    $lines = Payslip::query()->with('lines')->where('payroll_run_id', $run->id)->where('employee_id', $this->bertil->id)->sole()->lines
        ->filter(fn ($line): bool => $codes[$line->pay_code_id] === 'GR_OT_A')->map(fn ($line): int => $line->rate_ore->ore)->sort()->values()->all();

    // 32 000 kr / 173 = 184,97110 kr: 145 % = 268,21 kr (31 March), 135 % = 249,71 kr (1 April).
    expect($lines)->toBe([24_971, 26_821]);
});

it('earns Detaljhandeln comp time with the factor of the day worked across 1 April 2026', function () {
    $this->travelTo(kindsFeatureAt('2026-04-08 10:00:00'));
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $this->company, $this->owner, ['overtime_pay_code' => 'DH_OT_A']);
    kindsFeatureAgreement($this->bertil, ['overtime_compensation' => 'COMP_TIME'], 'svenskhandel-handels-detaljhandel', 'ALLA');
    kindsFeatureWeekFrom($this->bertil, '2026-03-30', ['2026-03-31' => ['08:00', '18:00'], '2026-04-01' => ['08:00', '18:00']]);

    app(AttestPeriod::class)($this->owner, $this->company, kindsFeatureAt('2026-03-30'), kindsFeatureAt('2026-04-05'));
    app(SendToPayroll::class)($this->owner, $this->company, kindsFeatureAt('2026-03-30'), kindsFeatureAt('2026-04-05'));

    // D1 § 9.6 s. 37–38: 1 h 30 min per hour at 50 % (until 31 March 2026), 1 h 21 min per hour at 35 % (from 1 April).
    expect(CompTimeEntry::query()->orderBy('minutes')->get()->map(fn (CompTimeEntry $entry): string => "{$entry->pay_code} {$entry->worked_minutes} {$entry->factor} {$entry->minutes}")->all())
        ->toBe(['DH_OT_A 60 1.35 81', 'DH_OT_A 60 1.5 90']);
});

it('pays only the higher of OB and overtime on a Detaljhandeln evening, in the run', function () {
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $this->company, $this->owner, ['overtime_pay_code' => 'DH_OT_A']);
    kindsFeatureAgreement($this->bertil, [], 'svenskhandel-handels-detaljhandel', 'ALLA');
    // Monday 9 November to 20:00: overtime 17–19 (35 %), 19–20 (70 %). OB 50 % 18:15–20:00 is 105 min on the day.
    kindsFeatureWeekFrom($this->bertil, '2026-11-09', ['2026-11-09' => ['08:00', '20:00']]);

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    // 18:15–19:00: OB 50 % is higher than 35 %, so the 35 % supplement is taken back (−0,75 h). 19–20: 70 % ≥ 50 %, so the OB is (−1 h).
    expect(kindsFeatureReports())->toBe(['DH_OB_50 -1.00 2026-11-09', 'DH_OB_50 1.75', 'DH_OT_70 1.00 2026-11-09', 'DH_OT_A 2.00 2026-11-09', 'DH_OT_A_TL -0.75 2026-11-09']);

    $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
    app(PayrollRuns::class)->calculate($this->owner, $run);
    $codes = PayCode::query()->pluck('code', 'id');
    $lines = Payslip::query()->with('lines')->where('payroll_run_id', $run->id)->where('employee_id', $this->bertil->id)->sole()->lines->keyBy(fn ($line) => $codes[$line->pay_code_id]);

    // 32 000 kr / 166 = 192,77108 kr an hour (D1 § 8.1). OB 50 % = 96,39 kr × 0,75 h; the 35 % supplement 67,47 kr × −0,75 h.
    // So 18:15–19:00 pays the hour and 50 %, 19:00–20:00 the hour and 70 %, 17:00–18:15 the hour and 35 %.
    expect((float) $lines['DH_OB_50']->quantity)->toBe(0.75)
        ->and($lines['DH_OB_50']->amount_ore->ore)->toBe(7_229)
        ->and((float) $lines['DH_OT_A_TL']->quantity)->toBe(-0.75)
        ->and($lines['DH_OT_A_TL']->amount_ore->ore)->toBe(-5_060)
        ->and($lines['DH_OT_A']->rate_ore->ore)->toBe(26_024)
        ->and($lines['DH_OT_70']->rate_ore->ore)->toBe(32_771);
});

it('sends a period twice only once, and opening a day takes the negative reports back', function () {
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $this->company, $this->owner, ['overtime_pay_code' => 'DH_OT_A']);
    kindsFeatureAgreement($this->bertil, [], 'svenskhandel-handels-detaljhandel', 'ALLA');
    kindsFeatureWeekFrom($this->bertil, '2026-11-09', ['2026-11-09' => ['08:00', '20:00']]);

    // Every supplement-only code the take-back may use exists as a pay code once the agreement is bound.
    expect(PayCode::query()->whereIn('code', ['DH_OT_A_TL', 'DH_OT_70_TL', 'DH_OT_100_TL', 'DH_MT_A_TL', 'DH_MT_70_TL', 'DH_MT_100_TL'])->count())->toBe(6);

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);
    $first = kindsFeatureReports();
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    expect(kindsFeatureReports())->toBe($first)
        ->and($first)->toContain('DH_OB_50 -1.00 2026-11-09', 'DH_OT_A_TL -0.75 2026-11-09');

    app(ReopenAttestation::class)($this->owner, $this->company, Attestation::query()->whereDate('date', '2026-11-11')->sole());

    expect(TimeReport::query()->where('hours', '<', 0)->count())->toBe(0)
        ->and(array_values(array_filter(kindsFeatureReports(), fn (string $report): bool => str_starts_with($report, 'DH_OT'))))->toBe([]);
});

it('does not take back OB for a day that was not sent, and says so', function () {
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $this->company, $this->owner, ['overtime_pay_code' => 'DH_OT_A']);
    kindsFeatureAgreement($this->bertil, [], 'svenskhandel-handels-detaljhandel', 'ALLA');
    // Tuesday to 20:00: overtime 17–20 with OB 50 % 18:15–20:00. Tuesday is already in a time report, so it is not sent (its OB neither).
    kindsFeatureWeekFrom($this->bertil, '2026-11-09', ['2026-11-10' => ['08:00', '20:00']]);
    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    TimeReport::create(['employee_id' => $this->bertil->id, 'date' => '2026-11-10', 'hours' => '1', 'pay_code_id' => PayCode::query()->where('code', 'TIMLON')->value('id'), 'status' => 'SUBMITTED']);

    $result = app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    // The overtime is paid in full (wage + 35 % / 70 %); no −OB or −supplement for Tuesday. So never less than the higher of the two.
    expect(implode(' ', $result['skipped']))->toContain('2026-11-10')
        ->and(array_values(array_filter(kindsFeatureReports(), fn (string $report): bool => str_starts_with($report, 'DH_'))))->toBe(['DH_OT_70 1.00 2026-11-10', 'DH_OT_A 2.00 2026-11-10'])
        ->and(Attestation::query()->whereDate('date', '2026-11-13')->sole()->details['week']['take_backs_not_sent'])->toHaveCount(2);

    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->assertSee('2026-11-10: 60 min OB eller övertidstillägg på samma tid drogs inte tillbaka, eftersom dagen inte är skickad till lönen.');
});

it('sends the week without a take-back whose pay code is missing, and says so', function () {
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $this->company, $this->owner, ['overtime_pay_code' => 'DH_OT_A']);
    kindsFeatureAgreement($this->bertil, [], 'svenskhandel-handels-detaljhandel', 'ALLA');
    kindsFeatureWeekFrom($this->bertil, '2026-11-09', ['2026-11-09' => ['08:00', '20:00']]);
    PayCode::query()->where('code', 'DH_OT_A_TL')->delete();
    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);

    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    expect(array_values(array_filter(kindsFeatureReports(), fn (string $report): bool => str_starts_with($report, 'DH_'))))->toBe(['DH_OB_50 -1.00 2026-11-09', 'DH_OB_50 1.75', 'DH_OT_70 1.00 2026-11-09', 'DH_OT_A 2.00 2026-11-09']);

    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->assertSee('2026-11-09: 45 min OB eller övertidstillägg på samma tid drogs inte tillbaka, eftersom lönearten DH_OT_A_TL saknas i lönen.');
});
