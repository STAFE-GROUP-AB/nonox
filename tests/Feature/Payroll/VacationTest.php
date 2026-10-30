<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\CloseVacationYear;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\VacationYearClosing;
use App\Modules\Payroll\Models\WorkSchedule;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Calendar\SwedishHolidays;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->runs = app(PayrollRuns::class);
});

/** Calculates the run and returns the employee's payslip lines by code. */
function vacationPayslip(PayrollRun $run, int $employeeId): array
{
    test()->runs->calculate(test()->owner, $run);
    $payslip = Payslip::query()->with('lines')->where('employee_id', $employeeId)->where('payroll_run_id', $run->id)->sole();

    return [$payslip, $payslip->lines->keyBy(fn ($line) => PayCode::query()->find($line->pay_code_id)?->code)];
}

it('counts semesterdagar as semesterlagen 3 a § does: no weekends, holidays or the three eves', function () {
    expect(SwedishHolidays::isVacationDay(CarbonImmutable::parse('2026-06-18')))->toBeTrue()
        ->and(SwedishHolidays::isVacationDay(CarbonImmutable::parse('2026-06-19')))->toBeFalse()
        ->and(SwedishHolidays::isVacationDay(CarbonImmutable::parse('2026-12-24')))->toBeFalse()
        ->and(SwedishHolidays::isVacationDay(CarbonImmutable::parse('2026-12-31')))->toBeFalse()
        ->and(SwedishHolidays::isVacationDay(CarbonImmutable::parse('2026-06-06')))->toBeFalse()
        ->and(SwedishHolidays::isVacationDay(CarbonImmutable::parse('2026-06-13')))->toBeFalse();
});

it('pays the supplement under sammalöneregeln and keeps the monthly salary', function () {
    $anna = Payroll::employee($this->owner, $this->company);
    $run = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->recordAbsence($this->owner, $run, ['employee_id' => $anna->id, 'type' => 'VACATION', 'date_from' => '2026-06-15', 'date_to' => '2026-06-19', 'hours' => '8']);

    [$payslip, $lines] = vacationPayslip($run, $anna->id);

    // Midsommarafton (19 June) is not a semesterdag: four days, each 0.43 % of 32 000 kr.
    expect($lines['MANADSLON']->amount_ore->ore)->toBe(3_200_000)
        ->and($lines['SEMESTERTILLAGG']->amount_ore->ore)->toBe(4 * 13_760)
        ->and((string) $lines['SEMESTERTILLAGG']->quantity)->toStartWith('4')
        ->and($lines->has('OBETALD_SEMESTER'))->toBeFalse()
        ->and($payslip->warnings)->toBe([]);
});

it('pays procentregeln from the earning year for an hourly employee', function () {
    $linda = Payroll::employee($this->owner, $this->company, ['first_name' => 'Linda', 'personal_number' => '198001011231', 'start_date' => '2026-01-01', 'salary_form' => 'HOURLY', 'salary' => '180', 'employment_type' => 'TIMANSTALLD']);
    $hours = PayCode::query()->where('code', 'TIMLON')->sole();

    foreach (['2026-01', '2026-02', '2026-03'] as $period) {
        $this->travelTo(CarbonImmutable::parse("{$period}-05 10:00:00"));
        $run = $this->runs->create($this->owner, $this->company, $period);
        $this->runs->addInput($this->owner, $run, ['employee_id' => $linda->id, 'pay_code_id' => $hours->id, 'quantity' => '100']);
        $this->runs->calculate($this->owner, $run);
        $this->runs->approve($this->admin, $run->fresh());
    }

    $this->travelTo(CarbonImmutable::parse('2026-06-02 10:00:00'));
    $run = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->recordAbsence($this->owner, $run, ['employee_id' => $linda->id, 'type' => 'VACATION', 'date_from' => '2026-06-08', 'date_to' => '2026-06-12', 'hours' => '8']);

    [, $lines] = vacationPayslip($run, $linda->id);

    // Employed 90 of 365 days in the earning year: 25 × 90 / 365 = 6.16, rounded up to 7 paid days.
    // 12 % of 54 000 kr is 6 480 kr, 925,71 kr per day, five days.
    expect($lines['SEMESTERLON']->amount_ore->ore)->toBe(462_857)
        ->and($lines['SEMESTERLON']->rate_ore->ore)->toBe(92_571)
        ->and($lines->has('SEMESTERAVDRAG'))->toBeFalse();
});

it('makes days beyond the balance unpaid and says so', function () {
    $bo = Payroll::employee($this->owner, $this->company, ['first_name' => 'Bo', 'personal_number' => '199001011239', 'start_date' => '2026-05-01']);
    $run = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->recordAbsence($this->owner, $run, ['employee_id' => $bo->id, 'type' => 'VACATION', 'date_from' => '2026-06-08', 'date_to' => '2026-06-09', 'hours' => '8']);

    [$payslip, $lines] = vacationPayslip($run, $bo->id);

    expect($lines['OBETALD_SEMESTER']->amount_ore->ore)->toBeLessThan(0)
        ->and((string) $lines['OBETALD_SEMESTER']->quantity)->toStartWith('16')
        ->and($lines->has('SEMESTERTILLAGG'))->toBeFalse()
        ->and($payslip->warnings)->toContain('2 semesterdagar saknar betald semester och räknas som obetald semester.');
});

it('pays semesterersättning with every salary when the employment says so', function () {
    $linda = Payroll::employee($this->owner, $this->company, ['first_name' => 'Linda', 'personal_number' => '198001011231', 'start_date' => '2026-01-01', 'salary_form' => 'HOURLY', 'salary' => '180', 'employment_type' => 'TIMANSTALLD']);
    Employment::query()->where('employee_id', $linda->id)->update(['vacation_payout_with_salary' => true]);
    $run = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->addInput($this->owner, $run, ['employee_id' => $linda->id, 'pay_code_id' => PayCode::query()->where('code', 'TIMLON')->value('id'), 'quantity' => '100']);

    [, $lines] = vacationPayslip($run, $linda->id);

    expect($lines['SEMESTERERSATTNING']->amount_ore->ore)->toBe(216_000);
});

it('counts absence hours only on scheduled working days', function () {
    $anna = Payroll::employee($this->owner, $this->company);
    $run = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->recordAbsence($this->owner, $run, ['employee_id' => $anna->id, 'type' => 'LEAVE_UNPAID', 'date_from' => '2026-06-08', 'date_to' => '2026-06-14', 'hours' => '8']);

    [, $lines] = vacationPayslip($run, $anna->id);

    expect((string) $lines['FRANVAROAVDRAG']->quantity)->toStartWith('40');
});

it('earns fewer days after unpaid leave and only counts other absence beyond its limit', function () {
    $anna = Payroll::employee($this->owner, $this->company);
    $ledger = app(VacationLedger::class);
    $rules = RuleSet::for(CarbonImmutable::parse('2026-06-01'));
    $balance = fn () => $ledger->balances(collect([$anna]), Employment::query()->get()->keyBy('employee_id'), CarbonImmutable::parse('2026-06-01'), ['vacation_year_start_month' => 4], $rules)[$anna->id];

    expect($balance()['earned'])->toBe(25);

    Absence::create(['employee_id' => $anna->id, 'type' => 'SICK', 'date_from' => '2025-05-01', 'date_to' => '2025-08-31', 'hours' => '8', 'status' => 'APPROVED']);
    expect($balance()['earned'])->toBe(25);

    Absence::create(['employee_id' => $anna->id, 'type' => 'LEAVE_UNPAID', 'date_from' => '2025-10-01', 'date_to' => '2025-12-31', 'hours' => '8', 'status' => 'APPROVED']);
    // 365 − 92 days of unpaid leave: 25 × 273 / 365 = 18.7, rounded up to 19.
    expect($balance()['earned'])->toBe(19)
        ->and($balance()['year_start']->toDateString())->toBe('2026-04-01');
});

it('shows the vacation liability per employee', function () {
    Payroll::employee($this->owner, $this->company);

    $this->get(route('payroll.vacationLiability', ['datum' => '2026-04-30']))
        ->assertOk()
        ->assertSee('Semesterlöneskuld')
        ->assertSee('Anna Kock')
        ->assertSee("43\u{a0}547,40");
});

it('counts a scheduled weekend as semesterdagar only in a vacation shorter than five days (9 §)', function () {
    $schedule = WorkSchedule::create(['name' => 'Helgpersonal', 'pattern' => ['weeks' => [['8', '8', '8', '8', '0', '8', '8']]]]);
    $anna = Payroll::employee($this->owner, $this->company);
    Employment::query()->where('employee_id', $anna->id)->update(['work_schedule_id' => $schedule->id]);
    $run = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->recordAbsence($this->owner, $run, ['employee_id' => $anna->id, 'type' => 'VACATION', 'date_from' => '2026-06-06', 'date_to' => '2026-06-07', 'hours' => '8']);
    $this->runs->recordAbsence($this->owner, $run, ['employee_id' => $anna->id, 'type' => 'VACATION', 'date_from' => '2026-06-22', 'date_to' => '2026-06-28', 'hours' => '8']);

    [, $lines] = vacationPayslip($run, $anna->id);

    // 6 June is nationaldagen and never counts; Sunday 7 June does in a short vacation.
    // 22–28 June: five weekdays, so the weekend does not count, and Friday is not a working day: 4 days.
    expect((string) $lines['SEMESTERTILLAGG']->quantity)->toStartWith('5');
});

it('closes the semesterår: saves days beyond 20, pays the rest and vacation pay on variable pay once', function () {
    app(ModuleManager::class)->updateSettings(PayrollModule::KEY, $this->company, $this->owner, ['vacation_year_start_month' => 7]);
    $admin = Bookkeeping::member($this->company, 'admin');
    $anna = Payroll::employee($this->owner, $this->company, ['start_date' => '2024-01-01']);
    Absence::create(['employee_id' => $anna->id, 'type' => 'VACATION', 'date_from' => '2026-06-15', 'date_to' => '2026-06-17', 'hours' => '8', 'status' => 'APPROVED']);

    $this->travelTo(CarbonImmutable::parse('2026-05-05 10:00:00'));
    $may = $this->runs->create($this->owner, $this->company, '2026-05');
    $this->runs->addInput($this->owner, $may, ['employee_id' => $anna->id, 'pay_code_id' => PayCode::query()->where('code', 'OBTILLAGG')->value('id'), 'amount' => '1 000']);
    $this->runs->calculate($this->owner, $may);
    $this->runs->approve($admin, $may->fresh());

    $this->travelTo(CarbonImmutable::parse('2026-07-02 10:00:00'));
    $close = app(CloseVacationYear::class);
    $year = CarbonImmutable::parse('2025-07-01');
    $preview = $close->preview($this->company, $year)[$anna->id];

    expect($preview['earned'])->toBe(25)
        ->and($preview['taken'])->toBe(3)
        ->and($preview['left'])->toBe(22)
        ->and($preview['max_save'])->toBe(5)
        ->and($preview['variable_pay_ore'])->toBe(12_000)
        ->and(fn () => $close($this->owner, $this->company, $year, [$anna->id => 6]))->toThrow(ValidationException::class, 'högst 5');

    $close($this->owner, $this->company, $year, [$anna->id => 5]);

    $closing = VacationYearClosing::query()->sole();
    // 17 days at 32 000 kr × (4,6 % + 0,43 %) = 1 609,60 kr.
    expect($closing->days_paid_out)->toBe(17)
        ->and($closing->payout_ore)->toBe(17 * 160_960)
        ->and(fn () => $close($this->owner, $this->company, $year, []))->toThrow(ValidationException::class, 'redan stängt');

    $july = $this->runs->create($this->owner, $this->company, '2026-07');
    [, $lines] = vacationPayslip($july, $anna->id);
    $payouts = Payslip::query()->with('lines')->where('payroll_run_id', $july->id)->sole()->lines->where('description', '!=', 'Månadslön')->filter(fn ($line) => str_starts_with($line->description, 'Semesterlön'));

    expect($payouts->sum(fn ($line) => $line->amount_ore->ore))->toBe(17 * 160_960 + 12_000)
        ->and($payouts->every(fn ($line) => $line->tax_effect === 'ONE_OFF'))->toBeTrue();

    $this->runs->approve($admin, $july->fresh());
    expect($closing->fresh()->payroll_run_id)->toBe($july->id);

    $this->travelTo(CarbonImmutable::parse('2026-08-03 10:00:00'));
    $august = $this->runs->create($this->owner, $this->company, '2026-08');
    $this->runs->calculate($this->owner, $august);
    expect(Payslip::query()->with('lines')->where('payroll_run_id', $august->id)->sole()->lines->filter(fn ($line) => str_starts_with($line->description, 'Semesterlön'))->count())->toBe(0);

    $balance = app(VacationLedger::class)->balances(collect([$anna]), Employment::query()->get()->keyBy('employee_id'), CarbonImmutable::parse('2026-08-03'), ['vacation_year_start_month' => 7], RuleSet::for(CarbonImmutable::parse('2026-08-03')))[$anna->id];
    expect($balance['saved'])->toBe(5)
        ->and($balance['left'])->toBe(30);
});

it('counts qualifying absence at the pay the employee would have had, not the sick pay (16 b §)', function () {
    $admin = Bookkeeping::member($this->company, 'admin');
    $linda = Payroll::employee($this->owner, $this->company, ['first_name' => 'Linda', 'personal_number' => '198001011231', 'start_date' => '2026-01-01', 'salary_form' => 'HOURLY', 'salary' => '180', 'employment_type' => 'TIMANSTALLD']);

    foreach (['2026-01', '2026-02', '2026-03'] as $period) {
        $this->travelTo(CarbonImmutable::parse("{$period}-05 10:00:00"));
        $run = $this->runs->create($this->owner, $this->company, $period);
        $this->runs->addInput($this->owner, $run, ['employee_id' => $linda->id, 'pay_code_id' => PayCode::query()->where('code', 'TIMLON')->value('id'), 'quantity' => '100']);

        if ($period === '2026-02') {
            $this->runs->recordAbsence($this->owner, $run, ['employee_id' => $linda->id, 'type' => 'SICK', 'date_from' => '2026-02-10', 'date_to' => '2026-02-11', 'hours' => '8']);
        }

        $this->runs->calculate($this->owner, $run);
        $this->runs->approve($admin, $run->fresh());
    }

    $balance = app(VacationLedger::class)->balances(collect([$linda]), Employment::query()->get()->keyBy('employee_id'), CarbonImmutable::parse('2026-06-01'), ['vacation_year_start_month' => 4], RuleSet::for(CarbonImmutable::parse('2026-06-01')))[$linda->id];

    // 300 hours worked plus the 16 sick hours she would have worked, at 180 kr; the sick pay itself is left out.
    expect($balance['basis_ore'])->toBe(316 * 18_000);
});
