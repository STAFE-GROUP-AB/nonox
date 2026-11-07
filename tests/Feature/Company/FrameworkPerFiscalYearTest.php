<?php

declare(strict_types=1);

/*
 * ADR-059, addendum 2026-11-07, A.2.1 and package S1-P1: the K-regelverk is
 * a property of the fiscal year. BFNAR 2025:2 and 2025:3 apply by the
 * year's start (S1-R1.4), and a change of framework happens at a year
 * boundary, so a company that moves to K3 keeps the K2 report of its
 * earlier years. teams.reporting_framework is the latest year's framework.
 */

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Company\ChangeReportingFramework;
use App\Actions\Company\UpdateCompanyDetails;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\Closing\K2Reconfirmation;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Accounting\AnnualReportPage;
use App\Livewire\Company\CompanyDetailsForm;
use App\Models\AnnualReport;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year2026 = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
});

function frameworkNextYear(User $owner, Company $company): FiscalYear
{
    return app(CreateNextFiscalYear::class)($owner, $company);
}

/** @return array<string, mixed> */
function frameworkSettingsInput(Company $company, array $overrides = []): array
{
    return [
        'name' => $company->name,
        'vat_period' => $company->vat_period->value,
        'fiscal_year_start_month' => $company->fiscal_year_start_month,
        'accounting_method' => $company->accounting_method->value,
        'reporting_framework' => $company->reporting_framework->value,
        'f_skatt' => $company->f_skatt,
        'employer_registered' => $company->employer_registered,
        ...$overrides,
    ];
}

it('S1-R1.4 gives every existing year its company\'s framework when the column is added', function () {
    [, $k3Company] = Bookkeeping::company();
    $k3Company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $migration = require database_path('migrations/2026_12_17_100000_add_reporting_framework_to_fiscal_years.php');

    // PostgreSQL DDL is transactional: the test's transaction rolls this back.
    DB::statement('ALTER TABLE fiscal_years DROP CONSTRAINT fiscal_years_reporting_framework_check');
    DB::statement('ALTER TABLE fiscal_years DROP COLUMN reporting_framework, DROP COLUMN reporting_framework_changed_at, DROP COLUMN reporting_framework_changed_by, DROP COLUMN reporting_framework_change_reason');
    $migration->up();

    $frameworks = DB::table('fiscal_years')->whereIn('company_id', [$this->company->id, $k3Company->id])->pluck('reporting_framework', 'company_id');

    expect($frameworks[$this->company->id])->toBe('K2')
        ->and($frameworks[$k3Company->id])->toBe('K3')
        ->and(DB::table('fiscal_years')->whereNull('reporting_framework')->exists())->toBeFalse()
        ->and(fn () => DB::table('fiscal_years')->where('id', $this->year2026->id)->update(['reporting_framework' => null]))->toThrow(QueryException::class);
});

it('S1-R1.4 lets a new year inherit the framework of the year before it, else the company\'s', function () {
    expect($this->year2026->reporting_framework)->toBe(ReportingFramework::K2);

    // The year before decides, even when the company default says otherwise.
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $next = frameworkNextYear($this->owner, $this->company);

    expect($next->fresh()->reporting_framework)->toBe(ReportingFramework::K3);

    // A year with no year before it takes the company's framework.
    $first = FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);

    expect($first->fresh()->reporting_framework)->toBe(ReportingFramework::K2);
});

it('changes the latest open year and the company default, with the reason in the column only', function () {
    $next = frameworkNextYear($this->owner, $this->company);

    app(ChangeReportingFramework::class)($this->owner, $next, ReportingFramework::K3, 'Bolaget växer och ska följa K3.');

    $next->refresh();
    $entry = AuditLog::forCompany($this->company)->where('action', 'fiscal_year.framework_changed')->sole();

    expect($next->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($next->reporting_framework_changed_by)->toBe($this->owner->id)
        ->and($next->reporting_framework_changed_at)->not->toBeNull()
        ->and($next->reporting_framework_change_reason)->toBe('Bolaget växer och ska följa K3.')
        ->and($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($entry->payload)->toMatchArray(['fiscal_year_id' => $next->id, 'before' => 'K2', 'after' => 'K3', 'company_default_changed' => true])
        ->and(json_encode($entry->payload))->not->toContain('växer');
});

it('keeps the company default when an earlier open year is changed', function () {
    frameworkNextYear($this->owner, $this->company);

    app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K3);

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.framework_changed')->sole()->payload)
        ->toMatchArray(['company_default_changed' => false]);
});

it('refuses a closed year and a year whose annual report is adopted', function () {
    $next = frameworkNextYear($this->owner, $this->company);
    $this->year2026->forceFill(['status' => 'CLOSED'])->save();

    expect(fn () => app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K3))
        ->toThrow(ValidationException::class, 'Räkenskapsåret 2026 är stängt');

    AnnualReport::query()->forceCreate(['fiscal_year_id' => $next->id, 'signatories' => [], 'average_employees' => 0, 'dividend_ore' => 0, 'adoption_date' => '2028-04-15', 'updated_by' => $this->owner->id]);

    expect(fn () => app(ChangeReportingFramework::class)($this->owner, $next, ReportingFramework::K3))
        ->toThrow(ValidationException::class, 'Årsredovisningen för 2027 är fastställd');

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and($next->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.framework_changed')->exists())->toBeFalse();
});

it('lets only an owner or an administrator change the framework', function (string $role, bool $allowed) {
    $member = Bookkeeping::member($this->company, $role);
    $change = fn () => app(ChangeReportingFramework::class)($member, $this->year2026, ReportingFramework::K3);

    if ($allowed) {
        $change();
        expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K3);

        return;
    }

    expect($change)->toThrow(AuthorizationException::class)
        ->and($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2);
})->with([
    ['admin', true],
    ['accountant', false],
    ['bookkeeper', false],
    ['approver', false],
    ['auditor', false],
]);

it('asks for the K2 conditions and refuses K1 for an aktiebolag', function () {
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    expect(fn () => app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K2))
        ->toThrow(ValidationException::class, 'Bekräfta att företaget uppfyller villkoren')
        ->and(fn () => app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K1, null, ['framework_confirmed' => true]))
        ->toThrow(ValidationException::class, 'kan bara användas av enskilda firmor');

    app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K2, null, ['framework_confirmed' => true]);

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K2);
});

it('routes a change on the settings page to the latest open year and keeps the company column equal to it', function () {
    $next = frameworkNextYear($this->owner, $this->company);

    app(UpdateCompanyDetails::class)($this->owner, $this->company->fresh(), frameworkSettingsInput($this->company->fresh(), [
        'reporting_framework' => 'K3',
        'framework_change_reason' => 'Byter till K3.',
    ]));

    $latest = FiscalYear::query()->orderByDesc('start_date')->firstOrFail();

    expect($latest->id)->toBe($next->id)
        ->and($latest->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($latest->reporting_framework_change_reason)->toBe('Byter till K3.')
        ->and($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        // Invariant: the company column equals the latest year's framework.
        ->and($this->company->fresh()->reporting_framework)->toBe($latest->reporting_framework)
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.framework_changed')->count())->toBe(1);
});

it('refuses a change on the settings page when every year is closed', function () {
    $this->year2026->forceFill(['status' => 'CLOSED'])->save();

    try {
        app(UpdateCompanyDetails::class)($this->owner, $this->company->fresh(), frameworkSettingsInput($this->company->fresh(), ['reporting_framework' => 'K3', 'name' => 'Nytt namn AB']));
        $this->fail('The change was not refused.');
    } catch (ValidationException $exception) {
        expect($exception->errorBag)->toBe('updateCompanyDetails')
            ->and($exception->errors()['reporting_framework'][0])->toContain('Alla räkenskapsår är stängda');
    }

    // Nothing of the form was saved.
    expect($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and($this->company->fresh()->name)->not->toBe('Nytt namn AB');
});

it('still changes only the default of a company without fiscal years', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();

    app(UpdateCompanyDetails::class)($owner, $company, frameworkSettingsInput($company, ['reporting_framework' => 'K3']));

    expect($company->fresh()->reporting_framework)->toBe(ReportingFramework::K3);
});

it('names the year the choice applies to on the settings page', function () {
    frameworkNextYear($this->owner, $this->company);

    Livewire::test(CompanyDetailsForm::class, ['company' => $this->company->fresh()])
        ->assertSee('Valet gäller räkenskapsåret 2027 och de år som följer. Tidigare år behåller sitt regelverk.')
        ->assertDontSee('Varför byter företaget regelverk?')
        ->set('state.reporting_framework', 'K3')
        ->assertSee('Varför byter företaget regelverk?')
        ->set('state.framework_change_reason', 'Större bolag.')
        ->call('save')
        ->assertHasNoErrors();

    expect(FiscalYear::query()->orderByDesc('start_date')->firstOrFail()->reporting_framework_change_reason)->toBe('Större bolag.');
});

it('keeps the K2 report of 2026 for a company on K3 from 2027, and refuses the 2027 report', function () {
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    $next = frameworkNextYear($this->owner, $this->company);
    app(ChangeReportingFramework::class)($this->owner, $next, ReportingFramework::K3);
    $company = $this->company->fresh();

    expect($company->reporting_framework)->toBe(ReportingFramework::K3)
        ->and(AnnualReportDocument::supports($company, $this->year2026))->toBeTrue()
        ->and(AnnualReportDocument::refusal($company, $this->year2026))->toBeNull()
        ->and(AnnualReportDocument::supports($company, $next))->toBeFalse()
        ->and(AnnualReportDocument::refusal($company, $next))->toContain('NONOX tar ännu inte fram årsredovisningar enligt K3')
        // Without a year the company's framework decides, as before.
        ->and(AnnualReportDocument::supports($company))->toBeFalse();

    $this->get(route('accounting.annualReport.show', $this->year2026))->assertOk();
    $this->get(route('accounting.annualReport.pdf', $this->year2026))->assertOk();
    $this->get(route('accounting.annualReport.show', $next))->assertForbidden();
    $this->get(route('accounting.annualReport.pdf', $next))->assertForbidden();
    $this->get(route('accounting.annualReport.ixbrl', $next))->assertForbidden();

    Livewire::test(AnnualReportPage::class, ['year' => $this->year2026->id])
        ->assertSee('Texter och underskrifter')
        ->assertDontSee('Årsredovisning enligt K3 finns inte ännu');

    Livewire::test(AnnualReportPage::class, ['year' => $next->id])
        ->assertSee('Årsredovisning enligt K3 finns inte ännu')
        ->assertDontSee('Texter och underskrifter');
});

it('asks for the K2 conditions by the year\'s framework, not the company default', function () {
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    expect(K2Reconfirmation::applies($this->company->fresh(), $this->year2026))->toBeTrue();

    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K2])->save();

    expect(K2Reconfirmation::applies($this->company->fresh(), $this->year2026->fresh()))->toBeFalse();
});

it('shows no other company\'s fiscal year (404) and changes none of them', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    // Safe in the test: reads the other company's year by its company id.
    $otherYear = CompanyScope::bypass(fn () => FiscalYear::query()->where('company_id', $other->id)->firstOrFail());

    CurrentCompany::set($this->company);

    expect(FiscalYear::query()->find($otherYear->id))->toBeNull();

    $this->get(route('accounting.annualReport.show', $otherYear->id))->assertNotFound();

    expect(fn () => app(ChangeReportingFramework::class)($this->owner, $otherYear, ReportingFramework::K3))
        ->toThrow(AuthorizationException::class);

    expect(CompanyScope::bypass(fn () => $otherYear->fresh()->reporting_framework))->toBe(ReportingFramework::K2);
});
