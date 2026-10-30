<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['phone' => '08-123 456 78'])->save();
    Payroll::setUp($this->company, $this->owner);
    PayrollRuleVersion::create(['key' => 'agi.schema_version', 'valid_from' => '2026-01-01', 'value' => ['version' => '1.1'], 'source_url' => 'https://example.test'])
        ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
    $this->admin = Bookkeeping::member($this->company, 'admin');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Payroll::employee($this->owner, $this->company);
    app(AddPayrollAccounts::class)($this->owner, $this->company);
    $this->runs = app(PayrollRuns::class);
    $this->run = $this->runs->create($this->owner, $this->company, '2026-03');
    $this->runs->calculate($this->owner, $this->run);
    $this->runs->approve($this->admin, $this->run->fresh());
    $this->modules = app(ModuleManager::class);
});

function settleTheMonth(): AgiSubmission
{
    test()->runs->markPaid(test()->owner, test()->run->fresh());
    test()->runs->post(test()->owner, test()->run->fresh());
    $agi = app(AgiSubmissions::class);

    return $agi->submitted(test()->owner, $agi->generate(test()->owner, test()->company, '2026-03'), 'KV-1');
}

it('cannot be turned off with unposted runs, undeclared months or unpaid outlays', function () {
    Expense::create(['employee_id' => $this->anna->id, 'type' => 'OUTLAY', 'date' => '2026-03-02', 'amount_ore' => 12_500, 'vat_ore' => 0, 'status' => 'APPROVED', 'description' => 'Taxi', 'payroll_run_id' => $this->run->id]);

    $check = $this->modules->disableCheck(PayrollModule::KEY, $this->company);

    expect($check->allowed())->toBeFalse()
        ->and($check->blockers)->toContain('Lönekörningen '.$this->run->fresh()->title().' är inte bokförd.')
        ->and($check->blockers)->toContain('Arbetsgivardeklarationen för 2026-03 är inte inlämnad.')
        ->and($check->blockers)->toContain('Ett godkänt utlägg är inte utbetalt med lönen.');

    Expense::query()->update(['status' => 'PAID']);
    settleTheMonth();

    expect($this->modules->disableCheck(PayrollModule::KEY, $this->company)->blockers)->toBe([]);
});

it('keeps a read-only payroll archive while the module is off', function () {
    $submission = settleTheMonth();
    $this->modules->disable(PayrollModule::KEY, $this->company, $this->owner, confirmed: true);
    $payslip = Payslip::query()->sole();

    $this->get(route('payroll.runs.index'))->assertNotFound();
    $this->get(route('dashboard'))->assertSee('Lönearkiv');
    $this->get(route('payroll.archive'))->assertOk()->assertSee($this->run->fresh()->title())->assertSee('Inlämnad');
    $this->get(route('payroll.archive.payslip', $payslip))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('payroll.archive.agi', $submission))->assertOk();
    $this->get(route('payroll.archive.reports'))->assertOk()->assertSee('Lönearter');

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->actingAs($bookkeeper)->get(route('payroll.archive'))->assertForbidden();
});

it('tells someone who is only an employee that Min sida is closed', function () {
    settleTheMonth();
    $employee = Bookkeeping::member($this->company, 'employee');
    $this->modules->disable(PayrollModule::KEY, $this->company, $this->owner, confirmed: true);

    $this->actingAs($employee)->get(route('dashboard'))->assertForbidden()->assertSee('Min sida är stängd');

    $this->modules->enable(PayrollModule::KEY, $this->company, $this->owner);

    $this->actingAs($employee->fresh())->get(route('dashboard'))->assertRedirect(route('payroll.portal.home'));
});
