<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\PayrollRunPage;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Run\TimeReportPay;
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
 * GitHub issue #15: approved hourly time reports from an earlier month that
 * no run paid are a question to the person running payroll. Nothing is paid
 * without the answer; the answer is kept on the run and frozen at approval.
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

function lateReport(Employee $employee, string $date, string $hours, string $status = 'APPROVED'): TimeReport
{
    return TimeReport::create([
        'employee_id' => $employee->id,
        'date' => $date,
        'hours' => $hours,
        'pay_code_id' => PayCode::query()->where('code', 'TIMLON')->value('id'),
        'status' => $status,
    ]);
}

/** The run's hourly pay lines from time reports, by description. */
function hourlyLines(PayrollRun $run): Collection
{
    return PayslipLine::query()
        ->whereIn('payslip_id', Payslip::query()->where('payroll_run_id', $run->id)->select('id'))
        ->where('pay_code_id', PayCode::query()->where('code', 'TIMLON')->value('id'))
        ->get()
        ->keyBy('description');
}

it('asks about approved hours from earlier months with the hours, the person and the months', function () {
    lateReport($this->linda, '2026-05-20', '8');
    lateReport($this->linda, '2026-05-21', '4.5');
    lateReport($this->linda, '2026-04-10', '2');
    lateReport($this->linda, '2026-05-22', '6', 'SUBMITTED');
    lateReport($this->linda, '2026-06-03', '7');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    Livewire::test(PayrollRunPage::class, ['run' => $run])
        ->assertSee('14,5 godkända timmar från tidigare månader har inte betalats ut.')
        ->assertSee('Linda Kock: 14,5 tim från april 2026, maj 2026')
        ->assertSee('Ta med i den här lönekörningen')
        ->assertSee('Inte nu');

    // Nothing is included without the answer.
    $this->runs->calculate($this->owner, $run);

    expect(hourlyLines($run)->keys()->all())->toBe(['Timlön enligt tidrapport'])
        ->and(TimeReport::query()->whereDate('date', '<', '2026-06-01')->whereNotNull('payroll_run_id')->count())->toBe(0);
});

it('pays the included hours as lines that say which month they belong to, and claims them', function () {
    $may = [lateReport($this->linda, '2026-05-20', '8'), lateReport($this->linda, '2026-05-21', '4.5')];
    $april = lateReport($this->linda, '2026-04-10', '2');
    lateReport($this->linda, '2026-06-03', '7');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    Livewire::test(PayrollRunPage::class, ['run' => $run])
        ->call('includeLateTimeReports')
        ->assertDispatched('toast', message: '3 tidrapporter tas med i den här lönekörningen. Beräkna lönekörningen igen.')
        ->assertDontSee('godkända timmar från tidigare månader har inte betalats ut')
        ->assertSee('Timmar från tidigare månader som tas med i den här lönekörningen: 14,5 tim.');

    $this->runs->calculate($this->owner, $run->fresh());
    $lines = hourlyLines($run);

    // 12,5 hours × 180 kr = 2 250 kr for May; 2 hours × 180 kr = 360 kr for April.
    expect($lines->keys()->sort()->values()->all())->toBe(['Timlön enligt tidrapport', 'Timlön enligt tidrapport (april 2026)', 'Timlön enligt tidrapport (maj 2026)'])
        ->and((float) $lines['Timlön enligt tidrapport (maj 2026)']->quantity)->toBe(12.5)
        ->and($lines['Timlön enligt tidrapport (maj 2026)']->amount_ore->ore)->toBe(225_000)
        ->and($lines['Timlön enligt tidrapport (maj 2026)']->explanation)->toMatchArray(['month' => '2026-05', 'time_report_ids' => [$may[0]->id, $may[1]->id]])
        ->and($lines['Timlön enligt tidrapport (april 2026)']->amount_ore->ore)->toBe(36_000)
        ->and((float) $lines['Timlön enligt tidrapport']->quantity)->toBe(7.0)
        ->and(TimeReport::query()->pluck('payroll_run_id')->unique()->all())->toBe([$run->id])
        ->and($april->fresh()->payroll_run_id)->toBe($run->id)
        ->and(AuditLog::query()->where('action', 'payroll.late_time_reports_included')->sole()->payload)->toMatchArray(['time_report_ids' => [$april->id, $may[0]->id, $may[1]->id]]);
});

it('leaves the hours unpaid on Inte nu and asks again in the next run', function () {
    $report = lateReport($this->linda, '2026-05-20', '8');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    Livewire::test(PayrollRunPage::class, ['run' => $run])
        ->call('skipLateTimeReports')
        ->assertDispatched('toast', message: 'Timmarna tas inte med nu. Frågan kommer tillbaka i nästa lönekörning.')
        ->assertDontSee('godkända timmar från tidigare månader har inte betalats ut')
        ->assertSee('Timmar från tidigare månader som inte tas med nu: 8 tim.');

    lateReport($this->linda, '2026-06-03', '1');
    $this->runs->calculate($this->owner, $run->fresh());
    $this->runs->approve($this->admin, $run->fresh());

    expect(hourlyLines($run)->keys()->all())->toBe(['Timlön enligt tidrapport'])
        ->and($report->fresh()->payroll_run_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'payroll.late_time_reports_skipped')->count())->toBe(1);

    $next = $this->runs->create($this->owner, $this->company, '2026-07');

    Livewire::test(PayrollRunPage::class, ['run' => $next])
        ->assertSee('8 godkända timmar från tidigare månader har inte betalats ut.')
        ->assertSee('Linda Kock: 8 tim från maj 2026');
});

it('keeps the answer when the run is calculated again or opened again', function () {
    $report = lateReport($this->linda, '2026-05-20', '8');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->decideLateTimeReports($this->owner, $run, true);

    $this->runs->calculate($this->owner, $run->fresh());
    $this->runs->calculate($this->owner, $run->fresh());
    expect(hourlyLines($run)['Timlön enligt tidrapport (maj 2026)']->amount_ore->ore)->toBe(144_000);

    $this->runs->reopen($this->owner, $run->fresh());
    expect($report->fresh()->payroll_run_id)->toBeNull();

    $this->runs->calculate($this->owner, $run->fresh());
    expect($report->fresh()->payroll_run_id)->toBe($run->id);

    // A report approved after the answer is asked about on its own; the earlier answer stands.
    lateReport($this->linda, '2026-05-25', '3');
    Livewire::test(PayrollRunPage::class, ['run' => $run->fresh()])
        ->assertSee('3 godkända timmar från tidigare månader har inte betalats ut.')
        ->assertSee('Timmar från tidigare månader som tas med i den här lönekörningen: 8 tim.');

    // Taking the answer back releases the reports and asks again.
    $this->runs->resetLateTimeReports($this->owner, $run->fresh());

    expect($report->fresh()->payroll_run_id)->toBeNull()
        ->and($run->fresh()->status)->toBe(PayrollRun::DRAFT);
    Livewire::test(PayrollRunPage::class, ['run' => $run->fresh()])
        ->assertSee('11 godkända timmar från tidigare månader har inte betalats ut.');
});

it('cannot change the answer once the run is attested', function () {
    lateReport($this->linda, '2026-05-20', '8');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->decideLateTimeReports($this->owner, $run, true);
    $this->runs->calculate($this->owner, $run->fresh());
    $this->runs->approve($this->admin, $run->fresh());

    expect(fn () => $this->runs->decideLateTimeReports($this->owner, $run->fresh(), false))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->runs->resetLateTimeReports($this->owner, $run->fresh()))->toThrow(AuthorizationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('payroll_runs')->where('id', $run->id)->update(['late_time_reports_included' => null])))->toThrow(QueryException::class, 'cannot be changed')
        ->and($run->fresh()->late_time_reports_included)->toHaveCount(1);

    // A report approved now for May is the next run's question, not this one's.
    lateReport($this->linda, '2026-05-26', '2');
    Livewire::test(PayrollRunPage::class, ['run' => $run->fresh()])
        ->assertDontSee('godkända timmar från tidigare månader');
});

it('does not ask while the report\'s own month still has an open run', function () {
    lateReport($this->linda, '2026-05-20', '8');
    $this->runs->create($this->owner, $this->company, '2026-05');
    $june = $this->runs->create($this->owner, $this->company, '2026-06');

    Livewire::test(PayrollRunPage::class, ['run' => $june])
        ->assertDontSee('godkända timmar från tidigare månader');
    expect(fn () => $this->runs->decideLateTimeReports($this->owner, $june, true))->toThrow(ValidationException::class);
});

it('leaves monthly-paid employees as they are', function () {
    $anna = Payroll::employee($this->owner, $this->company);
    $report = lateReport($anna, '2026-05-20', '8');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    Livewire::test(PayrollRunPage::class, ['run' => $run])
        ->assertDontSee('godkända timmar från tidigare månader');
    expect(fn () => $this->runs->decideLateTimeReports($this->owner, $run, true))->toThrow(ValidationException::class);

    $this->runs->calculate($this->owner, $run);

    expect($report->fresh()->payroll_run_id)->toBeNull();
});

it('never shows or pays another company\'s time reports', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $other, $otherOwner);
    $theirs = CurrentCompany::run($other, function () use ($otherOwner, $other) {
        $employee = Payroll::employee($otherOwner, $other, ['first_name' => 'Bo', 'personal_number' => '198001011231', 'start_date' => '2026-01-01', 'salary_form' => 'HOURLY', 'salary' => '200', 'employment_type' => 'TIMANSTALLD']);

        return lateReport($employee, '2026-05-20', '8');
    });
    CurrentCompany::set($this->company);
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    Livewire::test(PayrollRunPage::class, ['run' => $run])
        ->assertDontSee('godkända timmar från tidigare månader')
        ->assertDontSee('Bo Kock');
    expect(fn () => $this->runs->decideLateTimeReports($this->owner, $run, true))->toThrow(ValidationException::class);

    // Even an id of theirs written onto the run is never claimed.
    DB::table('payroll_runs')->where('id', $run->id)->update(['late_time_reports_included' => json_encode([$theirs->id])]);
    lateReport($this->linda, '2026-06-03', '1');
    $this->runs->calculate($this->owner, $run->fresh());

    expect(CurrentCompany::run($other, fn () => $theirs->fresh()->payroll_run_id))->toBeNull()
        ->and(hourlyLines($run)->keys()->all())->toBe(['Timlön enligt tidrapport']);
});

it('reads the late reports for all employees in one query', function () {
    foreach (range(1, 3) as $i) {
        $employee = Payroll::employee($this->owner, $this->company, ['first_name' => "Tim{$i}", 'personal_number' => '1980010112'.$i.Luhn::checkDigit("80010112{$i}"), 'start_date' => '2026-01-01', 'salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD']);
        lateReport($employee, '2026-05-03', '4');
    }
    lateReport($this->linda, '2026-04-03', '8');
    $run = $this->runs->create($this->owner, $this->company, '2026-06');

    DB::enableQueryLog();
    $late = app(TimeReportPay::class)->late($run);
    $selects = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from "time_reports"'));
    DB::disableQueryLog();

    expect($late)->toHaveCount(4)
        ->and($selects)->toHaveCount(1);
});
