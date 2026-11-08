<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\AnnualReport\SaveFrameworkChoice;
use App\Actions\AnnualReport\SavePriorYearSizeFigures;
use App\Actions\Company\ChangeReportingFramework;
use App\Domain\AnnualReport\K3Eligibility;
use App\Domain\AnnualReport\K3EligibilityStatus;
use App\Domain\Company\ReportingFramework;
use App\Models\AnnualReport;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

/*
 * Changing a year to K2 judges every later year as if the year before it were
 * already on K2, and still refuses what must stay K3 (review of S1-P11).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    $this->year2026 = FiscalYear::query()->whereYear('start_date', 2026)->firstOrFail();
    $this->year2027 = app(CreateNextFiscalYear::class)($this->owner, $this->company);
});

function rc3No(array $extra = []): array
{
    return array_merge(array_fill_keys(['k3_size_listed', 'k2_1_1a_a', 'k2_1_1a_b', 'k2_1_1a_c', 'k2_1_1a_f', 'k2_1_1a_g', 'k2_1_1a_h', 'k2_1_1a_i'], 'no'), $extra);
}

function rc3Big(): void
{
    app(SavePriorYearSizeFigures::class)(test()->owner, test()->company, ['start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'average_employees_fte' => '2', 'balance_sheet_total' => '2 000 000', 'net_turnover' => '4 000 000']);
    Bookkeeping::postRows(test()->owner, test()->company, '2026-03-01', [1930 => 400_000_000, 3001 => -400_000_000]);
    AnnualReport::create(['fiscal_year_id' => test()->year2026->id, 'average_employees' => 2, 'dividend_ore' => 0, 'signatories' => [], 'signing_date' => '2027-01-20', 'updated_by' => test()->owner->id]);
}

function rc3AllK3(array $years): void
{
    foreach ($years as $y) {
        $y->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    }
    test()->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
}

$change = fn ($test, $year) => app(ChangeReportingFramework::class)($test->owner, $year->fresh(), ReportingFramework::K2, null, ['framework_confirmed' => true]);

it('lets a year change to K2 when a later year needs K3 only because that year is on K3', function () use ($change) {
    rc3Big();
    rc3AllK3([$this->year2026, $this->year2027]);
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['answers' => rc3No(['k2_1_1b_a' => 'yes'])]);
    expect(K3Eligibility::forYear($this->year2027->fresh())->status)->toBe(K3EligibilityStatus::K3Required);

    $change($this, $this->year2026);

    expect($this->year2027->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and(K3Eligibility::forYear($this->year2027->fresh())->status)->toBe(K3EligibilityStatus::Undetermined);
});

it('still refuses a later year that needs K3 for a 1.1A reason', function () use ($change) {
    rc3AllK3([$this->year2026, $this->year2027]);
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['answers' => rc3No(['k2_1_1a_i' => 'yes'])]);

    expect(fn () => $change($this, $this->year2026))->toThrow(ValidationException::class, 'räkenskapsåret 2027');
    expect($this->year2027->fresh()->reporting_framework)->toBe(ReportingFramework::K3);
});

it('still refuses a later year whose "normalt" question is answered no', function () use ($change) {
    rc3Big();
    rc3AllK3([$this->year2026, $this->year2027]);
    // Year 2026 temporarily K2 so the normalt question is asked and stored for 2027.
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K2])->save();
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['answers' => rc3No(['k2_1_1b_a' => 'yes', 'k2_1_1c_normally' => 'no'])]);
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    expect(fn () => $change($this, $this->year2026))->toThrow(ValidationException::class, 'räkenskapsåret 2027');
});

it('refuses a three-year chain when the last year has a 1.1A reason', function () use ($change) {
    $year2028 = app(CreateNextFiscalYear::class)($this->owner, $this->company);
    rc3AllK3([$this->year2026, $this->year2027, $year2028]);
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $year2028->id, ['answers' => rc3No(['k2_1_1a_g' => 'yes'])]);

    expect(fn () => $change($this, $this->year2026))->toThrow(ValidationException::class, 'räkenskapsåret 2028');
    expect($year2028->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($this->year2027->fresh()->reporting_framework)->toBe(ReportingFramework::K3);
});

it('never refuses a change to K3 on eligibility', function () {
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['answers' => rc3No(['k2_1_1a_i' => 'yes'])]);
    app(ChangeReportingFramework::class)($this->owner, $this->year2026->fresh(), ReportingFramework::K3, null, []);

    expect($this->year2027->fresh()->reporting_framework)->toBe(ReportingFramework::K3);
});
