<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\ClosePayrollYear;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\PayrollReportsPage;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\PayrollYearClosing;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\Reports\PayrollReports;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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
    $this->payslip = Payslip::query()->sole();
    $this->reports = app(PayrollReports::class);
});

function postAndDeclare(): void
{
    test()->runs->markPaid(test()->owner, test()->run->fresh());
    test()->runs->post(test()->owner, test()->run->fresh());
    $agi = app(AgiSubmissions::class);
    $agi->submitted(test()->owner, $agi->generate(test()->owner, test()->company, '2026-03'), 'KV-1');
}

it('lists the payslips, pay codes and year to date of approved runs', function () {
    $journal = $this->reports->journal('2026-01', '2026-12');
    $codes = collect($this->reports->payCodes('2026-03', '2026-03'))->keyBy('code');
    $ytd = $this->reports->yearToDate(2026);

    expect($journal)->toHaveCount(1)
        ->and($journal[0]['name'])->toBe('Anna Kock')
        ->and($journal[0]['gross_ore'])->toBe($this->payslip->gross_ore->ore)
        ->and($codes['MANADSLON']['amount_ore'])->toBe(3_200_000)
        ->and($codes['MANADSLON']['lines'])->toBe(1)
        ->and($ytd[0]['tax_ore'])->toBe($this->payslip->tax_ore->ore)
        ->and($this->reports->journal('2026-04', '2026-12'))->toBe([])
        ->and($this->reports->costCenters('2026-03', '2026-03')[0]['name'])->toBe('Utan kostnadsställe');
});

it('reconciles payslips with the declaration and the salary voucher', function () {
    expect($this->reports->reconciliation(2026)[0]['declared_tax_ore'])->toBeNull();

    postAndDeclare();
    $row = $this->reports->reconciliation(2026)[0];

    expect($row['period'])->toBe('2026-03')
        ->and($row['declared_tax_ore'])->toBe($row['payslip_tax_ore'])
        ->and($row['booked_tax_ore'])->toBe($row['payslip_tax_ore'])
        ->and($row['declared_contributions_ore'])->toBe($row['payslip_contributions_ore'])
        ->and($row['booked_contributions_ore'])->toBe($row['payslip_contributions_ore']);
});

it('closes the payroll year once it is posted and declared, and then pays nothing in it', function () {
    $close = app(ClosePayrollYear::class);

    expect($close->blockers(2026))->toContain('Året är inte slut ännu.');

    $this->travelTo(CarbonImmutable::parse('2027-01-20 10:00:00'));

    expect($close->blockers(2026))->toBe(['Lönekörningen '.$this->run->fresh()->title().' är inte bokförd.', 'Arbetsgivardeklarationen för 2026-03 är inte inlämnad.'])
        ->and(fn () => $close($this->owner, $this->company, 2026))->toThrow(ValidationException::class);

    postAndDeclare();
    $closing = $close($this->owner, $this->company, 2026);

    expect($closing->totals['gross_ore'])->toBe($this->payslip->gross_ore->ore)
        ->and(fn () => $this->runs->create($this->owner, $this->company, '2026-12'))->toThrow(ValidationException::class, 'avslutat')
        ->and(fn () => $close($this->owner, $this->company, 2026))->toThrow(ValidationException::class, 'redan avslutat')
        ->and(fn () => PayrollYearClosing::query()->whereKey($closing->id)->update(['year' => 2025]))->toThrow(QueryException::class);
});

it('shows the reports and keeps personal reports from an auditor', function () {
    Livewire::test(PayrollReportsPage::class)
        ->set('from', '2026-03')
        ->set('to', '2026-03')
        ->assertSee('Anna Kock')
        ->set('report', 'pay-codes')
        ->assertSee('MANADSLON')
        ->set('report', 'year-end')
        ->assertSee('Året är inte slut ännu.');

    $this->get(route('payroll.reports'))->assertOk();

    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->actingAs($auditor);

    Livewire::test(PayrollReportsPage::class)
        ->assertSet('report', 'pay-codes')
        ->assertDontSee('Lönejournal')
        ->set('report', 'journal')
        ->assertDontSee('Anna Kock');
});
