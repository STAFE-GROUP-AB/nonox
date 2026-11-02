<?php

declare(strict_types=1);

use App\Domain\Overview\TodoItem;
use App\Models\AuditLog;
use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Actions\AttestDay;
use App\Modules\Planning\Actions\AttestPeriod;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\ReopenAttestation;
use App\Modules\Planning\Actions\SaveActualTime;
use App\Modules\Planning\Actions\SendToPayroll;
use App\Modules\Planning\Livewire\AttestPage;
use App\Modules\Planning\Livewire\PlanningSettingsForm;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\Support\PlanningTodos;
use App\Modules\StaffLedger\Actions\AppendEntry;
use App\Modules\StaffLedger\Actions\TimeProposals;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Livewire\TimeProposalsPage;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;
use Tests\Support\StaffLedger;

/*
 * Planering step 4 (PLANNING_MODULE.md §5–§8): plan against the staff
 * ledger, attest per day, person or "alla utan avvikelser", send to payroll
 * as approved time reports per pay code, reopen only while the run is open,
 * and the payroll run paying the hours and the agreement's OB. Gröna Riks
 * values from the shipped data file, verified only inside the test.
 */

function attestAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Europe/Stockholm');
}

function attestCheck(Person $person, string $in, ?string $out): void
{
    $location = test()->location;
    app(AppendEntry::class)($location, $person, ['type' => EntryType::CheckIn, 'method' => CheckInMethod::KioskPin, 'occurred_at' => attestAt($in)]);

    if ($out !== null) {
        app(AppendEntry::class)($location, $person, ['type' => EntryType::CheckOut, 'method' => CheckInMethod::KioskPin, 'occurred_at' => attestAt($out)]);
    }
}

function attestShift(Employee $employee, string $date, string $start, string $end, int $break = 0): void
{
    Planning::shift(test()->owner, test()->company, ['employee_id' => $employee->id, 'date' => $date, 'start' => $start, 'end' => $end, 'break_minutes' => $break]);
}

function attestPublish(): void
{
    app(PublishPeriod::class)(test()->owner, test()->company, attestAt('2026-11-09'), attestAt('2026-11-15'));
}

/** @return array<string, int> pay code => minutes sent for the employee */
function attestReports(Employee $employee): array
{
    $codes = PayCode::query()->pluck('code', 'id');
    $minutes = [];

    foreach (TimeReport::query()->where('employee_id', $employee->id)->orderBy('date')->get() as $report) {
        $code = $codes[$report->pay_code_id];
        $minutes[$code] = ($minutes[$code] ?? 0) + (int) round((float) $report->hours * 60);
    }

    ksort($minutes);

    return $minutes;
}

function attestAgreement(): void
{
    $owner = test()->owner;
    $company = test()->company;
    app(ModuleManager::class)->enable(AgreementsModule::KEY, $company, $owner);
    $setup = app(AgreementSetup::class);
    $binding = $setup->saveBinding($owner, $company, ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'MEMBER', 'bound_from' => '2025-01-01']);

    foreach ([test()->anna, test()->bertil] as $employee) {
        $setup->saveEmployment($owner, $company, Employment::query()->where('employee_id', $employee->id)->sole(), ['collective_agreement_id' => $binding->id, 'agreement_group' => '2']);
    }

    AgreementRuleVersion::query()->where('agreement_key', 'visita-hrf-grona-riks')->where('variant', 'MEMBER')->sole()->forceFill(['verified_by' => 'Granskare', 'verified_at' => now()])->save();
}

beforeEach(function () {
    $this->travelTo(attestAt('2026-11-20 10:00:00'));
    Notification::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    StaffLedger::enable($this->company, $this->owner);
    $this->actingAs($this->owner);
    $this->location = StaffLedger::location($this->owner, $this->company);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD', 'start_date' => '2026-01-01']);
    $this->bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234', ['start_date' => '2026-01-01']);
    $this->annaInLedger = StaffLedger::person($this->owner, $this->company, '19900615123', null, ['first_name' => 'Anna', 'last_name' => 'Kock', 'employee_id' => $this->anna->id]);
    $this->from = attestAt('2026-11-01');
    $this->to = attestAt('2026-11-30');
});

it('compares the plan with the ledger, attests the clean days in one click and leaves the deviations', function () {
    attestShift($this->anna, '2026-11-10', '08:00', '16:00', 30);
    attestShift($this->anna, '2026-11-11', '08:00', '16:00', 30);
    attestShift($this->bertil, '2026-11-11', '09:00', '17:00', 60);
    attestPublish();
    attestCheck($this->annaInLedger, '2026-11-10 07:58', '2026-11-10 16:03');
    attestCheck($this->annaInLedger, '2026-11-11 08:25', '2026-11-11 16:00');

    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->assertSee('Anna Kock')
        ->assertSee('Bertil Kock')
        ->assertSee('Började 25 min sent (planerat 08:00, incheckad 08:25).')
        ->assertSee('Inte kopplad till någon i personalliggaren: den planerade tiden används.')
        ->assertSee('Attestera alla utan avvikelser (2)')
        ->call('attestClean')
        ->assertDispatched('toast', message: '2 dagar är attesterade. 1 dag väntar: den har en avvikelse att rätta eller titta på.')
        ->assertSee('Skicka till lönen (2)');

    $anna = Attestation::query()->where('employee_id', $this->anna->id)->sole();

    expect($anna->status)->toBe(Attestation::ATTESTED)
        ->and($anna->date->toDateString())->toBe('2026-11-10')
        ->and($anna->basis)->toBe(['TIMLON' => 450])
        ->and($anna->planned_minutes)->toBe(450)
        ->and(Attestation::query()->where('employee_id', $this->bertil->id)->sole()->basis)->toBe([])
        ->and(TimeReport::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'planning.period_attested')->count())->toBe(1);

    // The day with a deviation is attested on its own, after a look.
    app(AttestDay::class)($this->owner, $this->company, $this->anna->id, attestAt('2026-11-11'));

    expect(Attestation::query()->where('employee_id', $this->anna->id)->whereDate('date', '2026-11-11')->sole()->basis)->toBe(['TIMLON' => 425]);
});

it('sends attested days to payroll as approved time reports, once', function () {
    attestShift($this->anna, '2026-11-10', '08:00', '16:00', 30);
    attestShift($this->bertil, '2026-11-10', '09:00', '17:00', 60);
    attestPublish();
    attestCheck($this->annaInLedger, '2026-11-10 08:00', '2026-11-10 16:00');
    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);

    $send = app(SendToPayroll::class);
    $first = $send($this->owner, $this->company, $this->from, $this->to);
    $again = $send($this->owner, $this->company, $this->from, $this->to);

    $annaDay = Attestation::query()->where('employee_id', $this->anna->id)->sole();

    expect($first)->toBe(['sent' => 2, 'weeks' => 0, 'reports' => 1, 'skipped' => []])
        ->and($again)->toBe(['sent' => 0, 'weeks' => 0, 'reports' => 0, 'skipped' => []])
        ->and(attestReports($this->anna))->toBe(['TIMLON' => 450])
        ->and(TimeReport::query()->sole()->only(['status', 'hours']))->toBe(['status' => 'APPROVED', 'hours' => '7.50'])
        ->and($annaDay->status)->toBe(Attestation::SENT)
        ->and($annaDay->time_report_ids)->toBe([TimeReport::query()->sole()->id])
        // A monthly-paid day on plan sends nothing: the salary pays it.
        ->and(attestReports($this->bertil))->toBe([])
        ->and(fn () => app(SaveActualTime::class)($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'intervals' => []]))->toThrow(ValidationException::class, 'Dagen är attesterad.');

    // The staff ledger does not propose the day again.
    expect(fn () => app(TimeProposals::class)->propose($this->owner, $this->company, $this->from, $this->to))
        ->toThrow(ValidationException::class, 'Planering är på');
});

it('blocks a missing check-out until the manager gives the time, and never touches the ledger', function () {
    attestShift($this->anna, '2026-11-10', '16:00', '23:00', 30);
    attestPublish();
    attestCheck($this->annaInLedger, '2026-11-10 16:00', null);
    $entries = Entry::query()->count();

    expect(fn () => app(AttestDay::class)($this->owner, $this->company, $this->anna->id, attestAt('2026-11-10')))
        ->toThrow(ValidationException::class, 'Incheckad 16:00 men aldrig utcheckad.');

    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->call('edit', $this->anna->id, '2026-11-10')
        ->assertSet('form.intervals.0.start', '16:00')
        ->set('form.intervals.0.end', '23:15')
        ->set('form.intervals.0.break_minutes', 30)
        ->call('saveActual')
        ->assertDispatched('toast', message: 'Den faktiska tiden är sparad. Personalliggaren är oförändrad.')
        ->call('attestDay', $this->anna->id, '2026-11-10')
        ->assertDispatched('toast', message: 'Dagen är attesterad.');

    $day = Attestation::query()->sole();

    expect($day->basis)->toBe(['TIMLON' => 405])
        ->and(collect($day->deviations)->pluck('code')->all())->toBe(['LATE_FINISH', 'MANUAL', 'EXTRA_TIME', 'NO_OB'])
        ->and(Entry::query()->count())->toBe($entries)
        ->and(AuditLog::query()->where('action', 'planning.actual_time_saved')->count())->toBe(1);
});

it('uses the plan as actual time when the staff ledger is off', function () {
    app(ModuleManager::class)->disable(StaffLedgerModule::KEY, $this->company, $this->owner, true);
    attestShift($this->anna, '2026-11-10', '08:00', '12:00');
    attestPublish();

    $result = app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to, onlyWithoutDeviations: true);

    expect($result)->toBe(['attested' => 1, 'skipped' => 0])
        ->and(Attestation::query()->sole()->basis)->toBe(['TIMLON' => 240]);
});

it('does not attest unpublished drafts or days after today', function () {
    attestShift($this->anna, '2026-11-10', '08:00', '12:00');
    attestShift($this->anna, '2026-11-24', '08:00', '12:00');

    expect(app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to))->toBe(['attested' => 0, 'skipped' => 0]);

    attestPublish();
    app(PublishPeriod::class)($this->owner, $this->company, attestAt('2026-11-23'), attestAt('2026-11-29'));

    expect(app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to))->toBe(['attested' => 1, 'skipped' => 0]);
});

it('refuses a day already reported through the staff ledger', function () {
    attestShift($this->anna, '2026-11-10', '08:00', '12:00');
    attestPublish();
    attestCheck($this->annaInLedger, '2026-11-10 08:00', '2026-11-10 12:00');
    TimeReport::create(['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'hours' => '4', 'pay_code_id' => PayCode::query()->where('code', 'TIMLON')->value('id'), 'status' => 'APPROVED']);

    expect(fn () => app(AttestDay::class)($this->owner, $this->company, $this->anna->id, attestAt('2026-11-10')))
        ->toThrow(ValidationException::class, 'Dagen finns redan i en tidrapport i lönen');

    Livewire::test(TimeProposalsPage::class)
        ->assertSee('Planering är på. Tiden jämförs med schemat')
        ->assertDontSee('id="tp-from"', false);
});

it('reopens a sent day while the payroll run is open, and refuses once the run is attested', function () {
    $admin = Bookkeeping::member($this->company, 'admin');
    attestShift($this->anna, '2026-11-10', '08:00', '12:00');
    attestPublish();
    attestCheck($this->annaInLedger, '2026-11-10 08:00', '2026-11-10 12:00');
    $runs = app(PayrollRuns::class);
    $run = $runs->create($this->owner, $this->company, '2026-11');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);
    $runs->calculate($this->owner, $run->fresh());

    $reopen = app(ReopenAttestation::class);
    $reopen($this->owner, $this->company, Attestation::query()->sole());

    expect(TimeReport::query()->count())->toBe(0)
        ->and($run->fresh()->status)->toBe(PayrollRun::DRAFT)
        ->and(Attestation::query()->sole()->status)->toBe(Attestation::OPEN)
        ->and(AuditLog::query()->where('action', 'planning.attestation_reopened')->count())->toBe(1);

    // Attested and sent again, the run pays it and is attested: the day is locked for good.
    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);
    $runs->calculate($this->owner, $run->fresh());
    $runs->approve($admin, $run->fresh());

    expect(fn () => $reopen($this->owner, $this->company, Attestation::query()->sole()))
        ->toThrow(ValidationException::class, 'Tiden är betald i lönekörningen för november 2026, som är attesterad.')
        ->and(TimeReport::query()->count())->toBe(1);

    // A new day in the attested month is not sent: a correction goes into the next run.
    attestShift($this->anna, '2026-11-12', '08:00', '12:00');
    app(PublishPeriod::class)($this->owner, $this->company, attestAt('2026-11-09'), attestAt('2026-11-15'));
    attestCheck($this->annaInLedger, '2026-11-12 08:00', '2026-11-12 12:00');
    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    $result = app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    expect($result['sent'])->toBe(0)
        ->and($result['skipped'][0])->toContain('Lönen för november 2026 är redan attesterad.');
});

it('lets only those with planning:attest attest, send and reopen', function () {
    attestShift($this->anna, '2026-11-10', '08:00', '12:00');
    attestPublish();
    $scheduler = Bookkeeping::member($this->company, 'scheduler');
    $accountant = Bookkeeping::member($this->company, 'accountant');
    $admin = Bookkeeping::member($this->company, 'admin');

    foreach ([$scheduler, $accountant] as $user) {
        expect(fn () => app(AttestDay::class)($user, $this->company, $this->anna->id, attestAt('2026-11-10')))->toThrow(AuthorizationException::class)
            ->and(fn () => app(AttestPeriod::class)($user, $this->company, $this->from, $this->to))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SendToPayroll::class)($user, $this->company, $this->from, $this->to))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveActualTime::class)($user, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'intervals' => []]))->toThrow(AuthorizationException::class);
    }

    $day = app(AttestDay::class)($admin, $this->company, $this->anna->id, attestAt('2026-11-10'));

    expect(fn () => app(ReopenAttestation::class)($scheduler, $this->company, $day))->toThrow(AuthorizationException::class)
        ->and(app(ReopenAttestation::class)($admin, $this->company, $day)->status)->toBe(Attestation::OPEN);

    $this->actingAs($scheduler);
    Livewire::test(AttestPage::class)->assertForbidden();
});

it('does not reach another company\'s attestation', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $other, $otherOwner);
    app(ModuleManager::class)->enable(PlanningModule::KEY, $other, $otherOwner);
    $foreign = Planning::employee($otherOwner, $other, 'Greta', '800101234');
    $theirs = CurrentCompany::run($other, fn () => Attestation::create(['employee_id' => $foreign->id, 'date' => '2026-11-10']));
    CurrentCompany::set($this->company);

    expect(Attestation::query()->find($theirs->id))->toBeNull()
        ->and(fn () => app(AttestDay::class)($this->owner, $this->company, $foreign->id, attestAt('2026-11-10')))->toThrow(ModelNotFoundException::class);

    Livewire::test(AttestPage::class)->call('reopen', $theirs->id)->assertNotFound();
});

it('loads the page in the same number of queries for 2 people and for 6', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    attestShift($this->anna, '2026-11-10', '08:00', '12:00');
    attestShift($this->bertil, '2026-11-10', '08:00', '12:00');
    attestPublish();
    attestCheck($this->annaInLedger, '2026-11-10 08:00', '2026-11-10 12:00');
    $count();
    $few = $count();

    foreach (['880202345', '770303456', '660404567', '550505678'] as $index => $nine) {
        $employee = Planning::employee($this->owner, $this->company, "Person{$index}", $nine, ['salary_form' => 'HOURLY', 'salary' => '140', 'employment_type' => 'TIMANSTALLD', 'start_date' => '2026-01-01']);
        $person = StaffLedger::person($this->owner, $this->company, '19'.$nine, null, ['employee_id' => $employee->id]);

        foreach ([10, 11, 12, 13] as $day) {
            attestShift($employee, "2026-11-{$day}", '10:00', '14:00');
            attestCheck($person, "2026-11-{$day} 10:00", "2026-11-{$day} 14:10");
        }
    }

    attestPublish();
    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);

    expect($count())->toBe($few);
});

it('puts the days to attest before the next payroll run on Översikt', function () {
    attestShift($this->anna, '2026-11-10', '08:00', '12:00');
    attestShift($this->bertil, '2026-11-10', '08:00', '12:00');
    attestShift($this->bertil, '2026-11-11', '08:00', '12:00');
    attestPublish();
    app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
    $todos = fn (): array => app(PlanningTodos::class)->todos($this->owner, $this->company, attestAt('2026-11-20'));

    $todo = collect($todos())->first(fn (TodoItem $item): bool => $item->key === 'planning-attest');

    expect($todo?->title)->toBe('3 dagar att attestera före lönen för november 2026');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    expect(collect($todos())->contains(fn (TodoItem $item): bool => $item->key === 'planning-attest'))->toBeFalse();
});

it('runs from plan to payslip: hours and the agreement\'s OB on the payslip', function () {
    attestAgreement();
    // Tue 17–23 with 30 min break; Sat 16–22, worked to 22:30. Bertil (monthly) Sat 12–20.
    attestShift($this->anna, '2026-11-10', '17:00', '23:00', 30);
    attestShift($this->anna, '2026-11-14', '16:00', '22:00');
    attestShift($this->bertil, '2026-11-14', '12:00', '20:00');
    attestPublish();
    attestCheck($this->annaInLedger, '2026-11-10 16:58', '2026-11-10 23:02');
    attestCheck($this->annaInLedger, '2026-11-14 16:00', '2026-11-14 22:30');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    // Tue: 330 min hourly pay, OB 20–23 = 180. Sat: 390 min, OB from 16:00 = 390. Bertil: OB 16–20 = 240 only.
    expect(attestReports($this->anna))->toBe(['GR_OB' => 570, 'TIMLON' => 720])
        ->and(attestReports($this->bertil))->toBe(['GR_OB' => 240]);

    $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
    app(PayrollRuns::class)->calculate($this->owner, $run);
    $codes = PayCode::query()->pluck('code', 'id');
    $lines = fn (Employee $employee) => Payslip::query()->with('lines')->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->sole()->lines->keyBy(fn ($line) => $codes[$line->pay_code_id]);

    $anna = $lines($this->anna);
    $bertil = $lines($this->bertil);

    // 12 h × 150 kr = 1 800 kr; 9,5 h OB × 27,59 kr = 262,11 kr (rate from 2026-04-01).
    expect((float) $anna['TIMLON']->quantity)->toBe(12.0)
        ->and($anna['TIMLON']->amount_ore->ore)->toBe(180_000)
        ->and((float) $anna['GR_OB']->quantity)->toBe(9.5)
        ->and($anna['GR_OB']->rate_ore->ore)->toBe(2759)
        ->and($anna['GR_OB']->amount_ore->ore)->toBe(26_211)
        ->and((float) $bertil['GR_OB']->quantity)->toBe(4.0)
        ->and($bertil['GR_OB']->amount_ore->ore)->toBe(11_036)
        ->and($bertil->has('TIMLON'))->toBeFalse()
        ->and(TimeReport::query()->whereNull('payroll_run_id')->count())->toBe(0);
});

it('counts a monthly employee\'s overtime per calendar week, waits for the whole week and never deducts', function () {
    // Bertil: 40 h a week. Planned Mon–Fri 08–17 with 1 h break = 40 h.
    foreach (['09', '10', '11', '12', '13'] as $day) {
        attestShift($this->bertil, "2026-11-{$day}", '08:00', '17:00', 60);
    }

    attestShift($this->bertil, '2026-11-16', '08:00', '17:00', 60);
    attestPublish();
    app(PublishPeriod::class)($this->owner, $this->company, attestAt('2026-11-16'), attestAt('2026-11-22'));
    $actual = fn (string $date, string $end) => app(SaveActualTime::class)($this->owner, $this->company, ['employee_id' => $this->bertil->id, 'date' => $date, 'intervals' => [['start' => '08:00', 'end' => $end, 'break_minutes' => 60]]]);
    // Monday 1 h 30 long, Tuesday 1 h short: the week is 30 min over 40 h.
    $actual('2026-11-09', '18:30');
    $actual('2026-11-10', '16:00');
    // This week is not over: 2 h long on Monday the 16th.
    $actual('2026-11-16', '19:00');

    Livewire::test(PlanningSettingsForm::class, ['company' => $this->company])
        ->set('settings.overtime_pay_code', 'OVERTID')
        ->call('save')
        ->assertHasNoErrors()
        ->set('settings.overtime_pay_code', 'BONUS')
        ->call('save')
        ->assertHasErrors('settings.overtime_pay_code');

    // Not every day attested yet: the week waits.
    app(AttestDay::class)($this->owner, $this->company, $this->bertil->id, attestAt('2026-11-09'));

    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->assertSee('Veckan: hittills 30 min utöver schemat. Övertid och mertid räknas mot veckoarbetstiden när hela veckan är attesterad – väntar på hela veckan.');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);

    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->assertSee('Veckan: 30 min övertid skickas med den här dagen (OVERTID).')
        ->assertSee('Veckan: hittills 120 min utöver schemat.');

    $result = app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);
    $friday = Attestation::query()->whereDate('date', '2026-11-13')->sole();

    expect($result['weeks'])->toBe(1)
        ->and(attestReports($this->bertil))->toBe(['OVERTID' => 30])
        ->and($friday->basis)->toBe(['OVERTID' => 30])
        ->and($friday->details['week']['overtime_minutes'])->toBe(30)
        ->and(TimeReport::query()->sole()->date->toDateString())->toBe('2026-11-13')
        ->and(app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to)['weeks'])->toBe(0);

    // Reopening another day of the week takes the week's overtime back, until the week is attested again.
    app(ReopenAttestation::class)($this->owner, $this->company, Attestation::query()->whereDate('date', '2026-11-10')->sole());

    expect(TimeReport::query()->count())->toBe(0)
        ->and($friday->fresh()->basis)->toBe([])
        ->and($friday->fresh()->details)->not->toHaveKey('week');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    expect(attestReports($this->bertil))->toBe(['OVERTID' => 30]);

    // Overtime without the agreement's rate is never paid at a guessed rate: the run stops and says so.
    $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');

    expect(fn () => app(PayrollRuns::class)->calculate($this->owner, $run))->toThrow(ValidationException::class, 'Övertid enligt tidrapport saknar belopp eller timpris.');
});

it('counts a week across a month boundary once, from all its days, when the period is the later month', function (bool $septemberAttested) {
    $this->travelTo(attestAt('2026-10-20 10:00:00'));
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $this->company, $this->owner, ['overtime_pay_code' => 'OVERTID']);

    // Bertil, 40 h a week: Mon 2026-09-28 to Fri 2026-10-02, 08–17 with 1 h break = 40 h planned.
    foreach (['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'] as $date) {
        attestShift($this->bertil, $date, '08:00', '17:00', 60);
    }

    app(PublishPeriod::class)($this->owner, $this->company, attestAt('2026-09-28'), attestAt('2026-10-04'));
    $actual = fn (string $date, string $end) => app(SaveActualTime::class)($this->owner, $this->company, ['employee_id' => $this->bertil->id, 'date' => $date, 'intervals' => [['start' => '08:00', 'end' => $end, 'break_minutes' => 60]]]);
    // 2 h long in September, 1 h long in October: 43 h, so 180 min overtime. October alone has 17 h.
    $actual('2026-09-28', '19:00');
    $actual('2026-10-01', '18:00');

    $september = [attestAt('2026-09-01'), attestAt('2026-09-30')];
    $october = [attestAt('2026-10-01'), attestAt('2026-10-31')];

    if ($septemberAttested) {
        app(AttestPeriod::class)($this->owner, $this->company, ...$september);
    } else {
        app(AttestDay::class)($this->owner, $this->company, $this->bertil->id, attestAt('2026-09-28'));
        app(AttestDay::class)($this->owner, $this->company, $this->bertil->id, attestAt('2026-09-29'));
    }

    app(AttestPeriod::class)($this->owner, $this->company, ...$october);
    $page = Livewire::withQueryParams(['fran' => '2026-10-01', 'till' => '2026-10-31'])->test(AttestPage::class);
    $result = app(SendToPayroll::class)($this->owner, $this->company, ...$october);

    if (! $septemberAttested) {
        $page->assertSee('Veckan: hittills 180 min utöver schemat. Övertid och mertid räknas mot veckoarbetstiden när hela veckan är attesterad – väntar på hela veckan.');

        expect($result['weeks'])->toBe(0)
            ->and(attestReports($this->bertil))->toBe([]);

        return;
    }

    $page->assertSee('Veckan: 180 min övertid skickas med den här dagen (OVERTID).');

    expect($result['weeks'])->toBe(1)
        ->and(attestReports($this->bertil))->toBe(['OVERTID' => 180])
        ->and(TimeReport::query()->sole()->date->toDateString())->toBe('2026-10-02')
        // Sending September afterwards, or October again, does not count the week twice.
        ->and(app(SendToPayroll::class)($this->owner, $this->company, ...$september)['weeks'])->toBe(0)
        ->and(app(SendToPayroll::class)($this->owner, $this->company, ...$october)['weeks'])->toBe(0)
        ->and(attestReports($this->bertil))->toBe(['OVERTID' => 180]);
})->with(['September attested' => true, 'a September day not attested' => false]);
