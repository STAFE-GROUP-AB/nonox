<?php

declare(strict_types=1);

use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\CompTimeEntries;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\CompTimePage;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\CompTimeEntry;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\Support\CompTimeLedger;
use App\Modules\Planning\Actions\AttestPeriod;
use App\Modules\Planning\Actions\ChooseCompensation;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\ReopenAttestation;
use App\Modules\Planning\Actions\SaveActualTime;
use App\Modules\Planning\Actions\SendToPayroll;
use App\Modules\Planning\Domain\WeekBasis;
use App\Modules\Planning\Livewire\AttestPage;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;
use Tests\Support\Planning;

/*
 * NONOX Avtal A5–A8 on top of Planering (docs/avtal/gap-analys.md §5a):
 * the agreement's rounding of OB, overtime over a calculation period,
 * comp time chosen per occasion with the agreement's factor, comp time
 * paid out by the payroll run, and sick pay that includes the OB of the
 * published schedule. Without the staff ledger the plan is the actual
 * time. Agreement values come from the shipped data files and are verified
 * only inside the test.
 */

function avtalTid(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Europe/Stockholm');
}

function avtalTidShift(Employee $employee, string $date, string $start, string $end, int $break = 0): void
{
    Planning::shift(test()->owner, test()->company, ['employee_id' => $employee->id, 'date' => $date, 'start' => $start, 'end' => $end, 'break_minutes' => $break]);
}

function avtalTidPublish(string $from, string $to): void
{
    app(PublishPeriod::class)(test()->owner, test()->company, avtalTid($from), avtalTid($to));
}

/** @param  array<string, mixed>  $terms */
function avtalTidAgreement(array $terms = []): void
{
    $owner = test()->owner;
    $company = test()->company;
    app(ModuleManager::class)->enable(AgreementsModule::KEY, $company, $owner);
    $setup = app(AgreementSetup::class);
    $binding = $setup->saveBinding($owner, $company, ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'MEMBER', 'bound_from' => '2025-01-01']);

    foreach ([test()->anna, test()->bertil] as $employee) {
        $setup->saveEmployment($owner, $company, Employment::query()->where('employee_id', $employee->id)->sole(), ['collective_agreement_id' => $binding->id, 'agreement_group' => '2', ...$terms]);
    }

    AgreementRuleVersion::query()->where('agreement_key', 'visita-hrf-grona-riks')->where('variant', 'MEMBER')->sole()->forceFill(['verified_by' => 'Granskare', 'verified_at' => now()])->save();
}

/** @param  array<string, mixed>  $settings */
function avtalTidSettings(array $settings): void
{
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, test()->company, test()->owner, $settings);
}

/** A full week Monday to Friday, 08–17 with an hour's break = 40 h. */
function avtalTidWeek(Employee $employee, string $monday): void
{
    $day = avtalTid($monday);

    for ($i = 0; $i < 5; $i++) {
        avtalTidShift($employee, $day->addDays($i)->toDateString(), '08:00', '17:00', 60);
    }
}

function avtalTidActual(Employee $employee, string $date, string $start, string $end, int $break = 60): void
{
    app(SaveActualTime::class)(test()->owner, test()->company, ['employee_id' => $employee->id, 'date' => $date, 'intervals' => [['start' => $start, 'end' => $end, 'break_minutes' => $break]]]);
}

beforeEach(function () {
    $this->travelTo(avtalTid('2026-11-25 10:00:00'));
    Notification::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    Payroll::agreementPremiums();
    $this->actingAs($this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD', 'start_date' => '2026-01-01']);
    $this->bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234', ['start_date' => '2026-01-01']);
    $this->from = avtalTid('2026-11-01');
    $this->to = avtalTid('2026-11-30');
});

it('rounds OB by the agreement: every started half hour per shift counts', function () {
    avtalTidAgreement();
    // OB in Gröna Riks starts at 20:00 on weekdays: 10 minutes become 30.
    avtalTidShift($this->anna, '2026-11-10', '17:00', '20:10');
    avtalTidPublish('2026-11-09', '2026-11-15');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    $day = Attestation::query()->where('employee_id', $this->anna->id)->sole();

    expect($day->basis)->toBe(['GR_OB' => 30, 'TIMLON' => 190])
        ->and(collect($day->deviations)->pluck('message')->implode(' '))->toContain('OB är avrundad enligt avtalet: varje påbörjad period om 30 min räknas hel, per pass (GR#§ 13 s. 21).');
});

it('takes a period\'s overtime as comp time with the agreement\'s factor, per occasion, and gives it back when the day is opened again', function () {
    avtalTidAgreement(['overtime_compensation' => 'COMP_TIME']);
    avtalTidSettings(['overtime_pay_code' => 'GR_OT_A']);
    avtalTidWeek($this->bertil, '2026-11-09');
    avtalTidPublish('2026-11-09', '2026-11-15');
    // 20 minutes over the week; Gröna Riks rounds overtime up to a started half hour, and gives 1,5 h comp time per hour of GR_OT_A.
    avtalTidActual($this->bertil, '2026-11-09', '08:00', '17:20');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);

    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->assertSee('Veckan: 30 min övertid och mertid sparas som 0,75 h komptid när dagen skickas till lönen.')
        ->assertSee('Komptid, 0,75 h');

    $result = app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);
    $friday = Attestation::query()->whereDate('date', '2026-11-13')->sole();
    $entry = CompTimeEntry::query()->sole();

    expect($result['weeks'])->toBe(1)
        ->and(TimeReport::query()->count())->toBe(0)
        ->and($entry->kind)->toBe(CompTimeEntry::EARNED)
        ->and($entry->minutes)->toBe(45)
        ->and($entry->worked_minutes)->toBe(30)
        ->and($entry->factor)->toBe('1.5')
        ->and($entry->pay_code)->toBe('GR_OT_A')
        ->and($friday->details['week']['comp_time_minutes'])->toBe(45)
        ->and(CompTimeLedger::balance($this->bertil->id))->toBe(45);

    // Opening a day of the week takes the comp time back; this time the manager chooses money.
    app(ReopenAttestation::class)($this->owner, $this->company, Attestation::query()->whereDate('date', '2026-11-10')->sole());

    expect(CompTimeEntry::query()->count())->toBe(0);

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(ChooseCompensation::class)($this->owner, $this->company, $friday->fresh(), WeekBasis::PAY);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    $codes = PayCode::query()->pluck('code', 'id');

    expect(CompTimeEntry::query()->count())->toBe(0)
        ->and(TimeReport::query()->get()->map(fn (TimeReport $report): string => $codes[$report->pay_code_id].' '.$report->hours)->all())->toBe(['GR_OT_A 0.50'])
        ->and(fn () => app(ChooseCompensation::class)($this->owner, $this->company, $friday->fresh(), WeekBasis::COMP_TIME))->toThrow(ValidationException::class);
});

it('measures overtime against a rotating schedule\'s period instead of each calendar week', function () {
    avtalTidSettings(['overtime_pay_code' => 'OVERTID', 'overtime_period_weeks' => 2, 'overtime_period_start' => '2026-11-09']);
    avtalTidWeek($this->bertil, '2026-11-09');
    avtalTidWeek($this->bertil, '2026-11-16');
    avtalTidPublish('2026-11-09', '2026-11-22');
    // Week one 90 minutes long, week two 60 minutes short: 30 minutes over the two-week period.
    avtalTidActual($this->bertil, '2026-11-09', '08:00', '18:30');
    avtalTidActual($this->bertil, '2026-11-17', '08:00', '16:00');

    expect(PlanningSettings::periodStart(PlanningSettings::resolve(['overtime_period_weeks' => 2, 'overtime_period_start' => '2026-11-09']), avtalTid('2026-11-22'))->toDateString())->toBe('2026-11-09')
        ->and(PlanningSettings::periodStart(PlanningSettings::resolve(['overtime_period_weeks' => 2, 'overtime_period_start' => '2026-11-09']), avtalTid('2026-11-08'))->toDateString())->toBe('2026-10-26');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    $result = app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);
    $report = TimeReport::query()->sole();

    expect($result['weeks'])->toBe(1)
        ->and($report->date->toDateString())->toBe('2026-11-20')
        ->and((string) $report->hours)->toBe('0.50');
});

it('warns when the calculation period is longer than the agreement allows', function () {
    avtalTidAgreement();
    avtalTidSettings(['overtime_pay_code' => 'GR_OT_A', 'overtime_period_weeks' => 30, 'overtime_period_start' => '2026-05-04']);
    // Gröna Riks: at most 16 weeks for monthly pay without a local agreement. The 30-week period from 4 May ends 29 November.
    $this->travelTo(avtalTid('2026-12-02 10:00:00'));
    avtalTidWeek($this->bertil, '2026-11-23');
    avtalTidPublish('2026-11-23', '2026-11-29');
    avtalTidActual($this->bertil, '2026-11-23', '08:00', '18:00');
    app(AttestPeriod::class)($this->owner, $this->company, avtalTid('2026-05-04'), avtalTid('2026-06-30'));
    app(AttestPeriod::class)($this->owner, $this->company, avtalTid('2026-11-01'), avtalTid('2026-11-30'));

    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->assertSee('Beräkningsperioden är 30 veckor, men Gröna Riks medger högst 16 veckor utan lokalt avtal (GR#§ 9 p. 1 s. 13).');
});

it('registers comp time taken, paid out and adjusted, pays a payout in the run and shows the balance', function () {
    $entries = app(CompTimeEntries::class);
    $entries->record($this->owner, $this->company, ['employee_id' => $this->bertil->id, 'kind' => CompTimeEntry::ADJUSTMENT, 'date' => '2026-11-02', 'hours' => '10']);
    $entries->record($this->owner, $this->company, ['employee_id' => $this->bertil->id, 'kind' => CompTimeEntry::PAID_OUT, 'date' => '2026-11-15', 'hours' => '4']);

    expect(fn () => $entries->record($this->owner, $this->company, ['employee_id' => $this->bertil->id, 'kind' => CompTimeEntry::TAKEN, 'date' => '2026-11-16', 'hours' => '7']))
        ->toThrow(ValidationException::class, 'Saldot räcker inte')
        ->and(CompTimeLedger::balance($this->bertil->id))->toBe(360);

    $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
    app(PayrollRuns::class)->calculate($this->owner, $run);
    $codes = PayCode::query()->pluck('code', 'id');
    $lines = Payslip::query()->with('lines')->where('payroll_run_id', $run->id)->where('employee_id', $this->bertil->id)->sole()->lines->keyBy(fn ($line) => $codes[$line->pay_code_id]);

    // 32 000 kr × 12 / 52 / 40 h = 184,62 kr an hour; 4 h.
    expect((float) $lines['KOMPTID']->quantity)->toBe(4.0)
        ->and($lines['KOMPTID']->rate_ore->ore)->toBe(18_462)
        ->and($lines['KOMPTID']->amount_ore->ore)->toBe(73_848);

    Livewire::test(CompTimePage::class)
        ->assertSee('6,00')
        ->set('person', $this->bertil->id)
        ->assertSee('Utbetald')
        ->set('form.employee_id', $this->bertil->id)
        ->set('form.kind', CompTimeEntry::TAKEN)
        ->set('form.date', '2026-11-20')
        ->set('form.hours', '2,5')
        ->call('record')
        ->assertHasNoErrors()
        ->assertSee('3,50');
});

it('lets only payroll staff change comp time and keeps it inside the company', function () {
    $viewer = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => app(CompTimeEntries::class)->record($viewer, $this->company, ['employee_id' => $this->bertil->id, 'kind' => CompTimeEntry::ADJUSTMENT, 'date' => '2026-11-02', 'hours' => '1']))
        ->toThrow(AuthorizationException::class);

    $entry = app(CompTimeEntries::class)->record($this->owner, $this->company, ['employee_id' => $this->bertil->id, 'kind' => CompTimeEntry::ADJUSTMENT, 'date' => '2026-11-02', 'hours' => '1']);
    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::set($other);

    expect(CompTimeEntry::query()->count())->toBe(0)
        ->and(fn () => CompTimeEntry::query()->findOrFail($entry->id))->toThrow(ModelNotFoundException::class)
        ->and(CompTimeLedger::balance($this->bertil->id))->toBe(0);

    CurrentCompany::set($this->company);
});

it('pays sick pay on the OB of the published shift and counts the schedule\'s OB in the karensavdrag', function () {
    avtalTidAgreement();
    // Prisbasbelopp 2026 comes verified from Payroll::agreementPremiums() in beforeEach.
    // Saturday 16–22: six hours of Gröna Riks OB at 27,59 kr.
    avtalTidShift($this->anna, '2026-11-14', '16:00', '22:00');
    avtalTidPublish('2026-11-09', '2026-11-15');
    CurrentCompany::run($this->company, fn () => Absence::create(['employee_id' => $this->anna->id, 'type' => 'SICK', 'date_from' => '2026-11-14', 'date_to' => '2026-11-14', 'extent_bp' => 10000, 'hours' => '6', 'status' => 'APPROVED']));

    $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
    app(PayrollRuns::class)->calculate($this->owner, $run);
    $lines = Payslip::query()->with('lines')->where('payroll_run_id', $run->id)->where('employee_id', $this->anna->id)->sole()->lines;
    $ob = $lines->firstWhere('description', 'Sjuklön 80 % av OB enligt schemat');
    $karens = $lines->first(fn ($line) => $line->description === 'Karensavdrag');

    // 6 h × 27,59 kr = 165,54 kr OB lost; 80 % = 132,43 kr. No attested time before the sick period, so the published schedule
    // from its first week is the average week; only the one week with a published shift counts (an empty week is not 0 h): 165,54 kr.
    $sickPay = $lines->first(fn ($line) => str_starts_with($line->description, 'Sjuklön 80 %,'));

    // Saturday is not in the payroll's weekly pattern, but the published shift says six hours were missed: 6 h × 150 kr × 80 %.
    expect($sickPay->explanation['hours'])->toBe('6.00')
        ->and($sickPay->amount_ore->ore)->toBe(72_000)
        ->and($ob)->not->toBeNull()
        ->and($ob->amount_ore->ore)->toBe(13_243)
        ->and($ob->explanation['reference_period']['weeks'])->toBe(1)
        ->and($ob->explanation['average_weekly_hours_source'])->toBe('SCHEDULE')
        ->and($karens->explanation['average_weekly_supplements_ore'])->toBe(16_554);
});

it('adds worked time on a day without a planned shift, so time still reaches payroll without the staff ledger', function () {
    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->call('addDay')
        ->assertSet('form.new', true)
        ->set('form.employee_id', $this->anna->id)
        ->set('form.date', '2026-11-11')
        ->set('form.intervals.0.start', '10:00')
        ->set('form.intervals.0.end', '14:00')
        ->call('saveActual')
        ->assertHasNoErrors()
        ->assertSee('Arbetade 10:00–14:00 utan planerat pass.');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);

    expect(Attestation::query()->where('employee_id', $this->anna->id)->sole()->basis)->toBe(['TIMLON' => 240]);
});

/** An attested day in "Tid att attestera", written as the attest step leaves it. */
function avtalTidAttested(Employee $employee, string $date, int $minutes): void
{
    CurrentCompany::run(test()->company, function () use ($employee, $date, $minutes): void {
        (new Attestation(['employee_id' => $employee->id, 'date' => $date]))
            ->forceFill(['status' => Attestation::ATTESTED, 'planned_minutes' => $minutes, 'actual_minutes' => $minutes, 'details' => ['ob' => []]])
            ->save();
    });
}

/** Bertil on irregular hours: no weekly hours on the employment, sick on Tuesday 17 November. */
function avtalTidIrregularSick(): void
{
    avtalTidAgreement();
    CurrentCompany::run(test()->company, function (): void {
        Employment::query()->where('employee_id', test()->bertil->id)->update(['weekly_hours' => null]);
        Absence::create(['employee_id' => test()->bertil->id, 'type' => 'SICK', 'date_from' => '2026-11-17', 'date_to' => '2026-11-17', 'extent_bp' => 10000, 'hours' => '8', 'status' => 'APPROVED']);
    });
}

it('takes the average week for the karensavdrag from the attested time before the sick period, not counting weeks without a record or with only absence', function () {
    avtalTidIrregularSick();

    // 26–29 October and 9–12 November: 4 × 8 h. 2–6 November is vacation and nothing worked. Weeks before 26 October have no record.
    foreach (['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'] as $date) {
        avtalTidAttested($this->bertil, $date, 480);
    }

    CurrentCompany::run($this->company, fn () => Absence::create(['employee_id' => $this->bertil->id, 'type' => 'VACATION', 'date_from' => '2026-11-02', 'date_to' => '2026-11-06', 'extent_bp' => 10000, 'hours' => '8', 'status' => 'APPROVED']));
    // A published shift after the sick day: the schedule ahead is not used while there is history.
    avtalTidShift($this->bertil, '2026-11-24', '08:00', '12:00');
    avtalTidPublish('2026-11-23', '2026-11-29');

    $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
    app(PayrollRuns::class)->calculate($this->owner, $run);
    $karens = Payslip::query()->with('lines')->where('payroll_run_id', $run->id)->where('employee_id', $this->bertil->id)->sole()->lines->firstWhere('description', 'Karensavdrag');

    // Two weeks of 32 h: 32 h a week; the karens time is 20 % of it.
    expect($karens->explanation['average_weekly_hours'])->toBe('32.00')
        ->and($karens->explanation['average_weekly_hours_source'])->toBe('HISTORY')
        ->and($karens->explanation['karens_hours'])->toStartWith('6.4')
        ->and($karens->explanation['weekly_hours_source'])->toBe('AVERAGE');
});

it('blocks the karensavdrag on irregular hours when there is neither attested time before the sick period nor a published schedule', function () {
    avtalTidIrregularSick();
    $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');

    expect(fn () => app(PayrollRuns::class)->calculate($this->owner, $run))
        ->toThrow(ValidationException::class, 'det finns varken attesterad tid före sjukperioden eller publicerade pass');
});
