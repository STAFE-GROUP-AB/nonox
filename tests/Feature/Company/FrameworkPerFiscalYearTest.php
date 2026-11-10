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
use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\Company\ChangeReportingFramework;
use App\Actions\Company\UpdateCompanyDetails;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\AnnualReport\K3AnnualReportDocument;
use App\Domain\Closing\K2Reconfirmation;
use App\Domain\Company\ReportingFramework;
use App\Domain\IncomeTax\Ne;
use App\Domain\IncomeTax\NeCodes;
use App\Livewire\Accounting\AnnualReportPage;
use App\Livewire\Company\CompanyDetailsForm;
use App\Models\Account;
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

it('S1-R1.4 lets a new year inherit the framework of the year before it, else of the nearest later year, else the company\'s', function () {
    expect($this->year2026->reporting_framework)->toBe(ReportingFramework::K2);

    // The year before decides, even when the company default says otherwise.
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $next = frameworkNextYear($this->owner, $this->company);

    expect($next->fresh()->reporting_framework)->toBe(ReportingFramework::K3);

    // An older year created after the newer ones takes the nearest later year's framework, not the company's.
    $older = FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);

    expect($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and($older->fresh()->reporting_framework)->toBe(ReportingFramework::K3);

    // With no other year at all, the company's framework.
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $first = CurrentCompany::run($company, fn () => FiscalYear::create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']));

    expect(CompanyScope::bypass(fn () => $first->fresh()->reporting_framework))->toBe(ReportingFramework::K3);
});

it('changes the chosen year and every later year, and the company default, with the reason in the columns only', function () {
    $next = frameworkNextYear($this->owner, $this->company);

    app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K3, 'Bolaget växer och ska följa K3.');

    $entries = AuditLog::forCompany($this->company)->where('action', 'fiscal_year.framework_changed')->orderBy('id')->get();

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($next->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($next->fresh()->reporting_framework_changed_by)->toBe($this->owner->id)
        ->and($next->fresh()->reporting_framework_changed_at)->not->toBeNull()
        ->and($next->fresh()->reporting_framework_change_reason)->toBe('Bolaget växer och ska följa K3.')
        ->and($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        // One audit entry per year, ids and enums only.
        ->and($entries->pluck('payload.fiscal_year_id')->all())->toBe([$this->year2026->id, $next->id])
        ->and($entries->first()->payload)->toMatchArray(['from_fiscal_year_id' => $this->year2026->id, 'before' => 'K2', 'after' => 'K3'])
        ->and($entries->last()->payload)->toMatchArray(['company_default_changed' => true])
        ->and(json_encode($entries->pluck('payload')->all()))->not->toContain('växer')
        ->and(K2Reconfirmation::applies($this->company->fresh(), $this->year2026->fresh()))->toBeFalse();
});

it('leaves earlier years as they are when a later year is chosen', function () {
    $next = frameworkNextYear($this->owner, $this->company);

    app(ChangeReportingFramework::class)($this->owner, $next, ReportingFramework::K3);

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and($next->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K3);
});

it('records it when only the company default was out of step with the years', function () {
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K3);

    expect($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.framework_changed')->exists())->toBeFalse()
        ->and(AuditLog::forCompany($this->company)->where('action', 'company.framework_default_synced')->sole()->payload)
        ->toMatchArray(['fiscal_year_id' => $this->year2026->id, 'before' => 'K2', 'after' => 'K3']);
});

it('refuses a closed or adopted year, also when it comes after the chosen one', function () {
    $next = frameworkNextYear($this->owner, $this->company);
    $this->year2026->forceFill(['status' => 'CLOSED'])->save();

    expect(fn () => app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K3))
        ->toThrow(ValidationException::class, 'Räkenskapsåret 2026 är stängt');

    AnnualReport::query()->forceCreate(['fiscal_year_id' => $next->id, 'signatories' => [], 'average_employees' => 0, 'dividend_ore' => 0, 'adoption_date' => '2028-04-15', 'updated_by' => $this->owner->id]);

    expect(fn () => app(ChangeReportingFramework::class)($this->owner, $next, ReportingFramework::K3))
        ->toThrow(ValidationException::class, 'Årsredovisningen för 2027 är fastställd');

    // A later closed year: the change is refused, not dropped silently.
    $this->year2026->forceFill(['status' => 'OPEN'])->save();
    AnnualReport::query()->where('fiscal_year_id', $next->id)->delete();
    $next->forceFill(['status' => 'CLOSED'])->save();

    expect(fn () => app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K3))
        ->toThrow(ValidationException::class, 'Räkenskapsåret 2027, som kommer efter 2026, är stängt');

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

it('applies a change on the settings page from the earliest open year without an adopted report', function () {
    $next = frameworkNextYear($this->owner, $this->company);

    app(UpdateCompanyDetails::class)($this->owner, $this->company->fresh(), frameworkSettingsInput($this->company->fresh(), [
        'reporting_framework' => 'K3',
        'framework_change_reason' => 'Byter till K3.',
    ]));

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($next->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($this->year2026->fresh()->reporting_framework_change_reason)->toBe('Byter till K3.')
        // Invariant: the company column equals the latest year's framework.
        ->and($this->company->fresh()->reporting_framework)->toBe($next->fresh()->reporting_framework)
        ->and(K2Reconfirmation::applies($this->company->fresh(), $this->year2026->fresh()))->toBeFalse()
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.framework_changed')->count())->toBe(2);
});

it('changes an earlier year to the company\'s own framework when that year is chosen', function () {
    $next = frameworkNextYear($this->owner, $this->company);
    app(ChangeReportingFramework::class)($this->owner, $next, ReportingFramework::K3);
    $this->company->forceFill(['k2_conditions_confirmed_at' => null, 'k2_conditions_confirmed_by' => null])->save();
    $company = $this->company->fresh();

    expect($company->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2);

    // Saving other details with the default year changes no year.
    app(UpdateCompanyDetails::class)($this->owner, $company, frameworkSettingsInput($company, ['reporting_framework' => 'K3']));

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2);

    // Choosing K3 from 2026 changes 2026 in one save, though the company column already says K3.
    app(UpdateCompanyDetails::class)($this->owner, $company->fresh(), frameworkSettingsInput($company->fresh(), [
        'reporting_framework' => 'K3',
        'framework_from_year' => $this->year2026->id,
    ]));

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($next->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($this->company->fresh()->k2_conditions_confirmed_at)->toBeNull()
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.framework_changed')->count())->toBe(2);
});

it('offers the year choice in the form when only an earlier year differs', function () {
    $next = frameworkNextYear($this->owner, $this->company);
    app(ChangeReportingFramework::class)($this->owner, $next, ReportingFramework::K3);

    Livewire::test(CompanyDetailsForm::class, ['company' => $this->company->fresh()])
        // Preselected so that saving changes nothing.
        ->assertSet('state.framework_from_year', $next->id)
        ->assertDontSee('Varför byter företaget regelverk?')
        ->set('state.framework_from_year', $this->year2026->id)
        ->assertSee('Varför byter företaget regelverk?')
        ->assertSee('Byt regelverk från och med räkenskapsår')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K3);
});

it('falls back to an eligible year when the chosen one is closed meanwhile', function () {
    $next = frameworkNextYear($this->owner, $this->company);

    $form = Livewire::test(CompanyDetailsForm::class, ['company' => $this->company->fresh()])
        ->set('state.reporting_framework', 'K3')
        ->assertSet('state.framework_from_year', $this->year2026->id);

    $this->year2026->forceFill(['status' => 'CLOSED'])->save();

    $form->call('save')->assertHasNoErrors();

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and($next->fresh()->reporting_framework)->toBe(ReportingFramework::K3);
});

it('marks only the latest year\'s audit entry as changing the company default', function () {
    $next = frameworkNextYear($this->owner, $this->company);

    app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K3);

    $payloads = AuditLog::forCompany($this->company)->where('action', 'fiscal_year.framework_changed')->orderBy('id')->pluck('payload');

    expect($payloads[0])->toMatchArray(['fiscal_year_id' => $this->year2026->id, 'company_default_changed' => false])
        ->and($payloads[1])->toMatchArray(['fiscal_year_id' => $next->id, 'company_default_changed' => true]);
});

it('applies a change on the settings page from a later year the person picks', function () {
    $next = frameworkNextYear($this->owner, $this->company);

    app(UpdateCompanyDetails::class)($this->owner, $this->company->fresh(), frameworkSettingsInput($this->company->fresh(), [
        'reporting_framework' => 'K3',
        'framework_from_year' => $next->id,
    ]));

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and($next->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K3);
});

it('refuses a change on the settings page when no year can take it, and saves nothing', function () {
    $this->year2026->forceFill(['status' => 'CLOSED'])->save();

    try {
        app(UpdateCompanyDetails::class)($this->owner, $this->company->fresh(), frameworkSettingsInput($this->company->fresh(), ['reporting_framework' => 'K3', 'name' => 'Nytt namn AB']));
        $this->fail('The change was not refused.');
    } catch (ValidationException $exception) {
        expect($exception->errorBag)->toBe('updateCompanyDetails')
            ->and($exception->errors()['reporting_framework'][0])->toContain('Det finns inget öppet räkenskapsår utan fastställd årsredovisning');
    }

    expect($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and($this->company->fresh()->name)->not->toBe('Nytt namn AB');
});

it('refuses a picked year that cannot take the change', function () {
    $next = frameworkNextYear($this->owner, $this->company);
    $this->year2026->forceFill(['status' => 'CLOSED'])->save();

    expect(fn () => app(UpdateCompanyDetails::class)($this->owner, $this->company->fresh(), frameworkSettingsInput($this->company->fresh(), [
        'reporting_framework' => 'K3',
        'framework_from_year' => $this->year2026->id,
    ])))->toThrow(ValidationException::class, 'Välj ett av de öppna räkenskapsåren');

    expect($next->fresh()->reporting_framework)->toBe(ReportingFramework::K2);
});

it('still changes only the default of a company without fiscal years', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();

    app(UpdateCompanyDetails::class)($owner, $company, frameworkSettingsInput($company, ['reporting_framework' => 'K3']));

    expect($company->fresh()->reporting_framework)->toBe(ReportingFramework::K3);
});

it('names the year the choice applies to on the settings page and lets the person pick a later one', function () {
    $next = frameworkNextYear($this->owner, $this->company);

    Livewire::test(CompanyDetailsForm::class, ['company' => $this->company->fresh()])
        ->assertSee('Valet gäller räkenskapsåret 2026 och de år som följer. Tidigare år behåller sitt regelverk.')
        ->assertDontSee('Varför byter företaget regelverk?')
        ->assertDontSee('Byt regelverk från och med räkenskapsår')
        ->set('state.reporting_framework', 'K3')
        ->assertSee('Varför byter företaget regelverk?')
        ->assertSee('Byt regelverk från och med räkenskapsår')
        ->set('state.framework_from_year', $next->id)
        ->assertSee('Valet gäller räkenskapsåret 2027 och de år som följer.')
        ->set('state.framework_change_reason', 'Större bolag.')
        ->call('save')
        ->assertHasNoErrors();

    expect($next->fresh()->reporting_framework_change_reason)->toBe('Större bolag.')
        ->and($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2);
});

it('offers no reason field when no year would store it', function () {
    $this->year2026->forceFill(['status' => 'CLOSED'])->save();

    Livewire::test(CompanyDetailsForm::class, ['company' => $this->company->fresh()])
        ->set('state.reporting_framework', 'K3')
        ->assertDontSee('Varför byter företaget regelverk?')
        ->call('save')
        ->assertHasErrors('reporting_framework');

    expect($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K2);
});

it('fills in NE by the year\'s framework: a sole trader on K1 for 2026 and K2 for 2027', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['legal_form' => 'EF', 'reporting_framework' => ReportingFramework::K1])->save();
    app(InitialiseBookkeeping::class)($company, $owner, CarbonImmutable::parse('2026-06-15'));
    CurrentCompany::set($company);
    $this->actingAs($owner->fresh());
    $year2026 = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    Account::query()->firstOrCreate(['number' => 2210], ['name' => 'Avsättningar', 'type' => AccountType::forNumber(2210)]);
    Bookkeeping::post($owner, $company, 1_000_000, 1930, 2210, '2026-11-01');
    $year2027 = app(CreateNextFiscalYear::class)($owner->fresh(), $company);
    app(ChangeReportingFramework::class)($owner->fresh(), $year2027, ReportingFramework::K2, null, ['framework_confirmed' => true]);

    expect($company->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and($year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K1);

    $fields = Ne::for($company->fresh(), $year2026->fresh(), null)->ne();

    // Förenklat årsbokslut: B12 (7330) is folded into B16 (7383), and the "not simplified" box stays empty.
    expect($fields)->not->toHaveKey(7330)
        ->and($fields)->not->toHaveKey(NeCodes::NOT_SIMPLIFIED)
        ->and($fields[7383] ?? 0)->toBe(10_000)
        ->and(Ne::for($company->fresh(), $year2027->fresh(), null)->ne()[NeCodes::NOT_SIMPLIFIED] ?? null)->toBe('X');
});

it('locks the fiscal year before saving the annual report, and refuses a year moved to K1 meanwhile', function () {
    // A K3 year saves its texts since S1-P10 (ADR-059 A.3), so the year moves to K1, which is still refused.
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K1])->save();

    // The caller's model still says K2; the locked row decides.
    $stale = $this->year2026->replicate()->forceFill(['id' => $this->year2026->id, 'reporting_framework' => ReportingFramework::K2]);
    $stale->exists = true;

    expect(fn () => app(SaveAnnualReport::class)($this->owner, $stale, ['average_employees' => '0']))
        ->toThrow(ValidationException::class, 'Räkenskapsåret har regelverket K1')
        ->and(AnnualReport::query()->exists())->toBeFalse();
});

it('keeps the K2 report of 2026 for a company on K3 from 2027, and routes the 2027 report to the K3 document', function () {
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    $next = frameworkNextYear($this->owner, $this->company);
    app(ChangeReportingFramework::class)($this->owner, $next, ReportingFramework::K3);
    $company = $this->company->fresh();

    expect($company->reporting_framework)->toBe(ReportingFramework::K3)
        ->and(AnnualReportDocument::supports($company, $this->year2026))->toBeTrue()
        ->and(AnnualReportDocument::refusal($company, $this->year2026))->toBeNull()
        ->and(AnnualReportDocument::supports($company, $next))->toBeFalse()
        // ADR-059 A.3 (S1-P10): a K3 year is not refused here; K3AnnualReportDocument gives its reasons.
        ->and(AnnualReportDocument::refusal($company, $next))->toBeNull()
        ->and(K3AnnualReportDocument::supports($company, $next))->toBeTrue()
        ->and(K3AnnualReportDocument::supports($company, $this->year2026))->toBeFalse()
        // Without a year the company's framework decides, as before.
        ->and(AnnualReportDocument::supports($company))->toBeFalse();

    $this->get(route('accounting.annualReport.show', $this->year2026))->assertOk();
    $this->get(route('accounting.annualReport.pdf', $this->year2026))->assertOk();
    // The 2027 K3 report is refused (the policies of the year are not chosen), so there is no preview and no PDF.
    $this->get(route('accounting.annualReport.show', $next))->assertNotFound();
    $this->get(route('accounting.annualReport.pdf', $next))->assertRedirect();
    $this->get(route('accounting.annualReport.ixbrl', $next))->assertForbidden();

    Livewire::test(AnnualReportPage::class, ['year' => $this->year2026->id])
        ->assertSee('Texter och underskrifter')
        ->assertDontSee('Årsredovisning enligt K3 finns inte ännu');

    Livewire::test(AnnualReportPage::class, ['year' => $next->id])
        ->assertSee('Det här hindrar årsredovisningen')
        ->assertSee('Uppgifter för K3');
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

it('records the K2 confirmation only when the save moves the company to K2', function () {
    $this->company->forceFill(['k2_conditions_confirmed_at' => null, 'k2_conditions_confirmed_by' => null])->save();

    // A tampered checkbox without a change writes no legal confirmation.
    app(UpdateCompanyDetails::class)($this->owner, $this->company->fresh(), frameworkSettingsInput($this->company->fresh(), ['framework_confirmed' => true]));

    expect($this->company->fresh()->k2_conditions_confirmed_at)->toBeNull();

    app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K3);
    app(UpdateCompanyDetails::class)($this->owner, $this->company->fresh(), frameworkSettingsInput($this->company->fresh(), ['reporting_framework' => 'K2', 'framework_confirmed' => true]));

    expect($this->company->fresh()->k2_conditions_confirmed_at)->not->toBeNull()
        ->and($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2);
});

it('says the framework applies from a year only when a year changed, and records a pure default sync', function () {
    // Every year is K3 already; only the company default says K2.
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    Livewire::test(CompanyDetailsForm::class, ['company' => $this->company->fresh()])
        ->set('state.reporting_framework', 'K3')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Uppgifterna för '.$this->company->name.' är sparade.');

    expect($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and(AuditLog::forCompany($this->company)->where('action', 'company.framework_default_synced')->count())->toBe(1);
});

it('records the default change when an earlier year changes but the latest one already had the framework', function () {
    $next = frameworkNextYear($this->owner, $this->company);
    $next->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    app(ChangeReportingFramework::class)($this->owner, $this->year2026, ReportingFramework::K3);

    expect($this->company->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.framework_changed')->sole()->payload)->toMatchArray(['company_default_changed' => false])
        ->and(AuditLog::forCompany($this->company)->where('action', 'company.framework_default_synced')->sole()->payload)->toMatchArray(['before' => 'K2', 'after' => 'K3']);
});
