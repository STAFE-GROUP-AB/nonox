<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\PayrollRunPage;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\PayrollModule;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * GitHub issue #15: the payroll run pays approved time reports. Hourly pay
 * from the reports in the run's month, each report paid once.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->runs = app(PayrollRuns::class);
    $this->linda = Payroll::employee($this->owner, $this->company, ['first_name' => 'Linda', 'personal_number' => '198001011231', 'start_date' => '2026-01-01', 'salary_form' => 'HOURLY', 'salary' => '180', 'employment_type' => 'TIMANSTALLD']);
});

function timeReport(Employee $employee, string $date, string $hours, string $status = 'APPROVED'): TimeReport
{
    return TimeReport::create([
        'employee_id' => $employee->id,
        'date' => $date,
        'hours' => $hours,
        'pay_code_id' => PayCode::query()->where('code', 'TIMLON')->value('id'),
        'status' => $status,
    ]);
}

/** The employee's payslip lines in the run, by pay code. */
function timeReportLines(PayrollRun $run, int $employeeId): Collection
{
    $payslip = Payslip::query()->with('lines')->where('employee_id', $employeeId)->where('payroll_run_id', $run->id)->sole();

    return $payslip->lines->keyBy(fn ($line) => PayCode::query()->find($line->pay_code_id)?->code);
}

it('pays the approved hours at the hourly rate and shows hours and rate on the payslip', function () {
    $reports = [timeReport($this->linda, '2026-06-03', '8'), timeReport($this->linda, '2026-06-04', '7.5')];
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    $this->runs->calculate($this->owner, $run);
    $line = timeReportLines($run, $this->linda->id)['TIMLON'];

    // 15,5 hours × 180 kr = 2 790 kr.
    expect($line->description)->toBe('Timlön enligt tidrapport')
        ->and((float) $line->quantity)->toBe(15.5)
        ->and($line->rate_ore->ore)->toBe(18_000)
        ->and($line->amount_ore->ore)->toBe(279_000)
        ->and($line->explanation['time_report_ids'])->toBe([$reports[0]->id, $reports[1]->id])
        ->and(TimeReport::query()->pluck('payroll_run_id')->unique()->all())->toBe([$run->id]);

    Livewire::test(PayrollRunPage::class, ['run' => $run->fresh()])
        ->assertSee('Timlön enligt tidrapport')
        ->assertSee('15,5 tim × 180,00 kr');
});

it('ignores reports outside the run month', function () {
    $before = timeReport($this->linda, '2026-05-31', '8');
    $after = timeReport($this->linda, '2026-07-01', '8');
    timeReport($this->linda, '2026-06-30', '4');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    $this->runs->calculate($this->owner, $run);

    expect((float) timeReportLines($run, $this->linda->id)['TIMLON']->quantity)->toBe(4.0)
        ->and($before->fresh()->payroll_run_id)->toBeNull()
        ->and($after->fresh()->payroll_run_id)->toBeNull();
});

it('ignores reports not approved and says how many hours are waiting', function () {
    timeReport($this->linda, '2026-06-03', '8');
    $submitted = timeReport($this->linda, '2026-06-04', '6.5', 'SUBMITTED');
    timeReport($this->linda, '2026-06-05', '3', 'REJECTED');
    timeReport($this->linda, '2026-07-02', '9', 'SUBMITTED');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    $this->runs->calculate($this->owner, $run);

    expect((float) timeReportLines($run, $this->linda->id)['TIMLON']->quantity)->toBe(8.0)
        ->and($submitted->fresh()->payroll_run_id)->toBeNull();

    Livewire::test(PayrollRunPage::class, ['run' => $run->fresh()])
        ->assertSee('6,5 timmar i perioden väntar på godkännande');
});

it('never pays a report twice, in a recalculation or in a second run for the month', function () {
    timeReport($this->linda, '2026-06-03', '8');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    $this->runs->calculate($this->owner, $run);
    $this->runs->calculate($this->owner, $run->fresh());

    expect(timeReportLines($run, $this->linda->id)->get('TIMLON')->amount_ore->ore)->toBe(144_000);

    // A report approved after the first calculation joins the run when it is calculated again.
    timeReport($this->linda, '2026-06-10', '2');
    $this->runs->calculate($this->owner, $run->fresh());
    expect((float) timeReportLines($run, $this->linda->id)['TIMLON']->quantity)->toBe(10.0);

    $extra = PayrollRun::create(['period' => '2026-06', 'payment_date' => '2026-06-30', 'type' => 'EXTRA']);
    $this->runs->addInput($this->owner, $extra, ['employee_id' => $this->linda->id, 'pay_code_id' => PayCode::query()->where('code', 'TIMLON')->value('id'), 'quantity' => '1']);
    $this->runs->calculate($this->owner, $extra);

    // The extra run pays only its own input: the reports belong to the regular run.
    expect((float) timeReportLines($extra, $this->linda->id)['TIMLON']->quantity)->toBe(1.0)
        ->and(timeReportLines($extra, $this->linda->id)->get('TIMLON')->description)->not->toBe('Timlön enligt tidrapport')
        ->and(TimeReport::query()->where('payroll_run_id', $run->id)->count())->toBe(2);
});

it('releases the reports when the run is opened again, so another run can pay them', function () {
    $report = timeReport($this->linda, '2026-06-03', '8');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->calculate($this->owner, $run);

    $this->runs->reopen($this->owner, $run->fresh());

    expect($report->fresh()->payroll_run_id)->toBeNull();
});

it('locks the reports once the run is approved', function () {
    $report = timeReport($this->linda, '2026-06-03', '8');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->calculate($this->owner, $run);
    $this->runs->approve($this->admin, $run->fresh());

    expect(fn () => DB::transaction(fn () => DB::table('time_reports')->where('id', $report->id)->update(['hours' => 12])))->toThrow(QueryException::class, 'approved payroll run')
        ->and(fn () => DB::transaction(fn () => DB::table('time_reports')->where('id', $report->id)->update(['payroll_run_id' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('time_reports')->where('id', $report->id)->delete()))->toThrow(QueryException::class)
        ->and($report->fresh()->payroll_run_id)->toBe($run->id);

    // A report for the month approved later can never be added to the approved run.
    $late = timeReport($this->linda, '2026-06-20', '5');
    expect(fn () => DB::transaction(fn () => DB::table('time_reports')->where('id', $late->id)->update(['payroll_run_id' => $run->id])))->toThrow(QueryException::class, 'cannot be added')
        ->and(fn () => $this->runs->calculate($this->owner, $run->fresh()))->toThrow(AuthorizationException::class);
});

it('leaves monthly-paid employees as they are', function () {
    $anna = Payroll::employee($this->owner, $this->company);
    $report = timeReport($anna, '2026-06-03', '8');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    $this->runs->calculate($this->owner, $run);
    $lines = timeReportLines($run, $anna->id);

    expect($lines->has('TIMLON'))->toBeFalse()
        ->and($lines['MANADSLON']->amount_ore->ore)->toBe(3_200_000)
        ->and($report->fresh()->payroll_run_id)->toBeNull();
});

it('blocks the run when an hourly employee has reports but no hourly rate', function () {
    Employment::query()->where('employee_id', $this->linda->id)->update(['hourly_rate_ore' => null]);
    timeReport($this->linda, '2026-06-03', '8');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    expect(fn () => $this->runs->calculate($this->owner, $run))->toThrow(ValidationException::class)
        ->and(TimeReport::query()->sole()->payroll_run_id)->toBeNull();
});

it('reads one query of time reports for all employees', function () {
    foreach (range(1, 3) as $i) {
        $employee = Payroll::employee($this->owner, $this->company, ['first_name' => "Tim{$i}", 'personal_number' => '1980010112'.$i.Luhn::checkDigit("80010112{$i}"), 'start_date' => '2026-01-01', 'salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD']);
        timeReport($employee, '2026-06-03', '4');
    }
    timeReport($this->linda, '2026-06-03', '8');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    DB::enableQueryLog();
    $this->runs->calculate($this->owner, $run);
    $selects = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from "time_reports"'));
    DB::disableQueryLog();

    expect($selects)->toHaveCount(1)
        ->and(TimeReport::query()->whereNotNull('payroll_run_id')->count())->toBe(4);
});

it('never pays or counts another company\'s time reports', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $other, $otherOwner);
    $theirs = CurrentCompany::run($other, function () use ($otherOwner, $other) {
        $employee = Payroll::employee($otherOwner, $other, ['first_name' => 'Bo', 'personal_number' => '198001011231', 'start_date' => '2026-01-01', 'salary_form' => 'HOURLY', 'salary' => '200', 'employment_type' => 'TIMANSTALLD']);
        timeReport($employee, '2026-06-04', '7', 'SUBMITTED');

        return timeReport($employee, '2026-06-03', '8');
    });
    CurrentCompany::set($this->company);
    timeReport($this->linda, '2026-06-03', '2');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    $this->runs->calculate($this->owner, $run);

    expect(Payslip::query()->where('payroll_run_id', $run->id)->count())->toBe(1)
        ->and((float) timeReportLines($run, $this->linda->id)['TIMLON']->quantity)->toBe(2.0)
        ->and(CurrentCompany::run($other, fn () => $theirs->fresh()->payroll_run_id))->toBeNull();

    Livewire::test(PayrollRunPage::class, ['run' => $run->fresh()])
        ->assertDontSee('väntar på godkännande');
});
