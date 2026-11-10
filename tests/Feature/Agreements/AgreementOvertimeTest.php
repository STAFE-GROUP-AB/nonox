<?php

declare(strict_types=1);

use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\CompTimeEntry;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\Support\CompTimeLedger;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use App\Modules\Planning\Actions\AttestPeriod;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\SaveActualTime;
use App\Modules\Planning\Actions\SendToPayroll;
use App\Modules\Planning\Livewire\AttestPage;
use App\Modules\Planning\PlanningModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;
use Tests\Support\Planning;

/*
 * Overtime and mertid from Planering to payroll (fix-overtid A1, A2, L5;
 * enligt AI-utredning 2026-11-02, juristgranskning återstår): per day under
 * Visita–Unionen, per occasion with Gröna Riks' ten minutes, the agreement's
 * supplement for hourly-paid staff, leave on scheduled time counted as
 * worked (arbetstidslagen 7 § andra stycket), four weeks at most without an
 * agreement (5 § andra stycket), and the vacation flag of paid-out comp
 * time. Without the staff ledger the plan is the actual time.
 */

function overtimeAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Europe/Stockholm');
}

/** Monday to Friday 08–17 with an hour's break (40 h) for $weeks weeks from $monday, published. */
function overtimeSchedule(Employee $employee, string $monday, int $weeks = 1): void
{
    $start = overtimeAt($monday);

    for ($day = 0; $day < 7 * $weeks; $day++) {
        $date = $start->addDays($day);

        if ($date->isWeekday()) {
            Planning::shift(test()->owner, test()->company, ['employee_id' => $employee->id, 'date' => $date->toDateString(), 'start' => '08:00', 'end' => '17:00', 'break_minutes' => 60]);
        }
    }

    app(PublishPeriod::class)(test()->owner, test()->company, $start, $start->addWeeks($weeks)->subDay());
}

function overtimeWorked(Employee $employee, string $date, string $end): void
{
    app(SaveActualTime::class)(test()->owner, test()->company, ['employee_id' => $employee->id, 'date' => $date, 'intervals' => [['start' => '08:00', 'end' => $end, 'break_minutes' => 60]]]);
}

function overtimeBind(Employee $employee, string $key, string $group): void
{
    $owner = test()->owner;
    $company = test()->company;
    app(ModuleManager::class)->enable(AgreementsModule::KEY, $company, $owner);
    $setup = app(AgreementSetup::class);
    $binding = $setup->saveBinding($owner, $company, ['agreement_key' => $key, 'variant' => 'MEMBER', 'bound_from' => '2025-01-01']);
    $setup->saveEmployment($owner, $company, Employment::query()->where('employee_id', $employee->id)->sole(), ['collective_agreement_id' => $binding->id, 'agreement_group' => $group]);
    AgreementRuleVersion::query()->where('agreement_key', $key)->where('variant', 'MEMBER')->sole()->forceFill(['verified_by' => 'Granskare', 'verified_at' => now()])->save();
}

/** @param  array<string, mixed>  $settings */
function overtimeSettings(array $settings): void
{
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, test()->company, test()->owner, $settings);
}

/** @return list<string> "CODE hours" of the time reports sent beyond the hourly pay */
function overtimeReports(): array
{
    $codes = PayCode::query()->pluck('code', 'id');

    return TimeReport::query()->orderBy('id')->get()
        ->map(fn (TimeReport $report): string => $codes[$report->pay_code_id].' '.$report->hours)
        ->reject(fn (string $line): bool => str_starts_with($line, 'TIMLON '))
        ->values()->all();
}

beforeEach(function () {
    $this->travelTo(overtimeAt('2026-11-25 10:00:00'));
    Notification::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    Payroll::agreementPremiums();
    $this->actingAs($this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '160', 'employment_type' => 'TIMANSTALLD', 'start_date' => '2026-01-01']);
    $this->bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234', ['start_date' => '2026-01-01']);
    $this->from = overtimeAt('2026-11-01');
    $this->to = overtimeAt('2026-11-30');
});

it('counts Visita–Unionen overtime per day: three days of 20 minutes are 90 minutes, not 60', function () {
    overtimeBind($this->bertil, 'visita-unionen', 'ALLA');
    overtimeSettings(['overtime_pay_code' => 'VU_OT_94']);
    overtimeSchedule($this->bertil, '2026-11-09');
    overtimeWorked($this->bertil, '2026-11-09', '17:20');
    overtimeWorked($this->bertil, '2026-11-10', '17:20');
    overtimeWorked($this->bertil, '2026-11-11', '17:20');
    // A shorter Thursday is not netted against the longer days under a daily method.
    overtimeWorked($this->bertil, '2026-11-12', '16:00');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    expect(overtimeReports())->toBe(['VU_OT_94 1.50'])
        ->and(PayCode::query()->where('code', 'VU_OT_94')->sole()->is_vacation_basis)->toBeFalse();
});

it('gives an hourly-paid Gröna Riks employee the overtime supplement only, beside the hourly pay', function () {
    overtimeBind($this->anna, 'visita-hrf-grona-riks', '2');
    overtimeSettings(['overtime_pay_code' => 'GR_OT_A']);
    overtimeSchedule($this->anna, '2026-11-09');
    // 8 minutes on Monday are not overtime (at most 10 minutes); 20 minutes on Tuesday are a started half hour.
    overtimeWorked($this->anna, '2026-11-09', '17:08');
    overtimeWorked($this->anna, '2026-11-10', '17:20');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);

    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->assertSee('8 min förlängning räknas inte som övertid: högst 10 min per tillfälle (GR#§ 12 p. 1.2 anm. s. 19).');

    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);
    $tuesday = TimeReport::query()->where('pay_code_id', PayCode::query()->where('code', 'TIMLON')->value('id'))->whereDate('date', '2026-11-10')->sole();

    // The hourly pay keeps every worked minute (8 h 20 min on Tuesday); the overtime adds only the supplement.
    expect(overtimeReports())->toBe(['GR_OT_A_TL 0.50'])
        ->and((string) $tuesday->hours)->toBe('8.33')
        ->and(PayCode::query()->where('code', 'GR_OT_A_TL')->sole()->is_vacation_basis)->toBeTrue();
});

it('gives an hourly-paid employee on Träindustriavtalet TMF_OT_TL, not TMF_OT with the hourly wage again', function () {
    $owner = $this->owner;
    $company = $this->company;
    app(ModuleManager::class)->enable(AgreementsModule::KEY, $company, $owner);
    $setup = app(AgreementSetup::class);
    $binding = $setup->saveBinding($owner, $company, ['agreement_key' => 'tmf-gs-traindustri', 'variant' => 'MEMBER', 'bound_from' => '2025-04-01']);
    $setup->saveEmployment($owner, $company, Employment::query()->where('employee_id', $this->anna->id)->sole(), ['collective_agreement_id' => $binding->id, 'agreement_group' => 'YRKES']);
    // Verified inside the test only: the shipped 2025 version cannot be verified (its amounts have no primary source).
    AgreementRuleVersion::query()->where('agreement_key', 'tmf-gs-traindustri')->whereDate('valid_from', '2025-04-01')->sole()->forceFill(['verified_by' => 'Granskare', 'verified_at' => now()])->save();
    overtimeSettings(['overtime_pay_code' => 'TMF_OT']);
    overtimeSchedule($this->anna, '2026-11-09');
    overtimeWorked($this->anna, '2026-11-10', '17:30');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    // TMF_OT is 1/175 of the monthly salary plus 58,50 kr; the hourly-paid get only the 58,50 kr (TI23 § 5 mom 2 s. 42).
    // 17:00–17:30 on a Tuesday is also OB a), paid beside the overtime (TI23 § 4 mom 12 kommentar s. 36–37).
    expect(overtimeReports())->toBe(['TMF_OB_A 0.50', 'TMF_OT_TL 0.50']);
});

it('counts approved leave on a scheduled day as worked when measuring overtime', function () {
    overtimeSettings(['overtime_pay_code' => 'OVERTID']);
    overtimeSchedule($this->bertil, '2026-11-09');
    CurrentCompany::run($this->company, fn () => Absence::create(['employee_id' => $this->bertil->id, 'type' => 'VACATION', 'date_from' => '2026-11-09', 'date_to' => '2026-11-09', 'extent_bp' => 10000, 'hours' => '8', 'status' => 'APPROVED']));
    // Monday not worked at all (vacation), Friday two hours longer.
    app(SaveActualTime::class)($this->owner, $this->company, ['employee_id' => $this->bertil->id, 'date' => '2026-11-09', 'intervals' => []]);
    overtimeWorked($this->bertil, '2026-11-13', '19:00');

    app(AttestPeriod::class)($this->owner, $this->company, $this->from, $this->to);
    app(SendToPayroll::class)($this->owner, $this->company, $this->from, $this->to);

    expect(overtimeReports())->toBe(['OVERTID 2.00'])
        ->and(PayCode::query()->where('code', 'OVERTID')->sole()->is_vacation_basis)->toBeTrue();
});

it('measures overtime over at most four weeks when no agreement binds the employment', function () {
    overtimeSettings(['overtime_pay_code' => 'OVERTID', 'overtime_period_weeks' => 6, 'overtime_period_start' => '2026-10-26']);
    overtimeSchedule($this->bertil, '2026-10-26', 4);
    overtimeWorked($this->bertil, '2026-11-20', '18:00');

    app(AttestPeriod::class)($this->owner, $this->company, overtimeAt('2026-10-26'), $this->to);

    Livewire::withQueryParams(['fran' => '2026-10-26', 'till' => '2026-11-30'])->test(AttestPage::class)
        ->assertSee('Utan kollektivavtal räknas övertid och mertid över högst 4 veckor (arbetstidslagen 5 § andra stycket), inte 6 veckor som i inställningarna.');

    app(SendToPayroll::class)($this->owner, $this->company, overtimeAt('2026-10-26'), $this->to);
    $report = TimeReport::query()->where('pay_code_id', PayCode::query()->where('code', 'OVERTID')->value('id'))->sole();

    // The four-week period 26 October – 22 November is over; its last attested day carries it. The six-week one would still be open.
    expect($report->date->toDateString())->toBe('2026-11-20')
        ->and((string) $report->hours)->toBe('1.00');
});

it('pays out comp time with the vacation flag of the overtime it was earned on, first in, first out', function () {
    $entry = fn (int $id, string $date, string $kind, int $minutes, ?string $code = null): CompTimeEntry => (new CompTimeEntry)->forceFill(['id' => $id, 'date' => $date, 'kind' => $kind, 'minutes' => $minutes, 'pay_code' => $code]);
    $history = collect([
        $entry(1, '2026-10-02', CompTimeEntry::EARNED, 90, 'VU_OT_94'),
        $entry(2, '2026-10-09', CompTimeEntry::EARNED, 60, 'OVERTID'),
        $entry(3, '2026-11-02', CompTimeEntry::PAID_OUT, -120),
        $entry(4, '2026-11-20', CompTimeEntry::TAKEN, -60),
    ]);

    $origins = CompTimeLedger::origins($history, ['VU_OT_94' => false, 'OVERTID' => true], true);

    // 90 min from Visita–Unionen overtime (semesterlön included) and 30 from overtime that is basis; then 30 left, and 30 beyond the balance.
    expect($origins[3])->toBe(['true' => 30, 'false' => 90])
        ->and($origins[4])->toBe(['true' => 60, 'false' => 0]);
});

it('keeps comp time paid out from Visita–Unionen overtime out of the vacation-pay basis, and other comp time in it', function () {
    overtimeBind($this->bertil, 'visita-unionen', 'ALLA');
    CurrentCompany::run($this->company, function () {
        CompTimeEntry::create(['employee_id' => $this->bertil->id, 'date' => '2026-10-30', 'kind' => CompTimeEntry::EARNED, 'minutes' => 90, 'worked_minutes' => 60, 'factor' => '1.5', 'pay_code' => 'VU_OT_94']);
        CompTimeEntry::create(['employee_id' => $this->bertil->id, 'date' => '2026-10-31', 'kind' => CompTimeEntry::ADJUSTMENT, 'minutes' => 60]);
        CompTimeEntry::create(['employee_id' => $this->bertil->id, 'date' => '2026-11-15', 'kind' => CompTimeEntry::PAID_OUT, 'minutes' => -150]);
    });

    $runs = app(PayrollRuns::class);
    $run = $runs->create($this->owner, $this->company, '2026-11');
    $runs->calculate($this->owner, $run);
    $codes = PayCode::query()->pluck('code', 'id');
    $all = Payslip::query()->with('lines')->where('payroll_run_id', $run->id)->where('employee_id', $this->bertil->id)->sole()->lines;
    $lines = $all->filter(fn ($line) => ($line->explanation['source'] ?? null) === 'comp_time')->sortBy(fn ($line) => $line->explanation['minutes'])->values();

    expect($lines)->toHaveCount(2)
        ->and([$lines[0]->explanation['minutes'], $lines[0]->explanation['vacation_basis']])->toBe([60, true])
        ->and([$lines[1]->explanation['minutes'], $lines[1]->explanation['vacation_basis']])->toBe([90, false])
        ->and($lines[1]->description)->toBe('Komptid, utbetald (inte semesterlönegrundande enligt avtalet)');

    // Once the run is approved, the vacation ledger counts the salary and the 60 minutes, not the 90 from Visita–Unionen overtime.
    DB::table('payroll_runs')->where('id', $run->id)->update(['status' => PayrollRun::APPROVED]);
    $basis = (int) app(VacationLedger::class)->bases([$this->bertil->id], overtimeAt('2026-01-01'), overtimeAt('2026-12-31'))[$this->bertil->id];
    $expected = $all->first(fn ($line) => $codes[$line->pay_code_id] === 'MANADSLON')->amount_ore->ore + $lines[0]->amount_ore->ore;

    expect($basis)->toBe($expected);
});
