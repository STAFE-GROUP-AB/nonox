<?php

declare(strict_types=1);

/*
 * BFL 3 kap. (calendar year, no minimum length) and the K-regelverk per
 * legal form. Enligt AI-utredning 2026-11-02, juristgranskning återstår.
 */

use App\Actions\Accounting\CarryForwardBalances;
use App\Actions\Accounting\ChangeFirstFiscalYear;
use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Company\CreateCompany;
use App\Actions\Company\UpdateCompanyDetails;
use App\Domain\Accounting\FiscalYearRules;
use App\Livewire\Accounting\FiscalYears;
use App\Livewire\Company\CompanyDetailsForm;
use App\Livewire\Company\CreateCompanyForm;
use App\Livewire\Setup\FirstRunWizard;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
});

/** @param array<string, mixed> $overrides */
function legalFormInput(array $overrides = []): array
{
    return [
        'name' => 'Regelbolaget',
        'org_number' => CompanyFactory::organisationNumber()->digits,
        'legal_form' => 'AB',
        'fiscal_year_start_month' => 1,
        'accounting_method' => 'FAKTURAMETODEN',
        'vat_registered' => true,
        'vat_period' => 'QUARTERLY',
        'reporting_framework' => 'K3',
        'f_skatt' => true,
        'employer_registered' => false,
        ...$overrides,
    ];
}

/** @param array<string, mixed> $overrides */
function createWithRules(array $overrides = []): Company
{
    return app(CreateCompany::class)->create(User::factory()->create(), legalFormInput($overrides));
}

/** @param array<string, mixed> $overrides */
function settingsInput(Company $company, array $overrides = []): array
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

function validationMessages(Closure $action): string
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return implode(' ', collect($exception->errors())->flatten()->all());
    }

    return '';
}

// B3: calendar year and length of the fiscal year (BFL 3 kap.)

it('makes a sole trader use the calendar year', function () {
    expect(validationMessages(fn () => createWithRules(['legal_form' => 'EF', 'fiscal_year_start_month' => 5])))
        ->toContain('En enskild firma ska ha kalenderår');

    $company = createWithRules(['legal_form' => 'EF', 'fiscal_year_start_month' => 1]);

    CurrentCompany::set($company);
    expect(FiscalYear::query()->sole()->label())->toBe('2026');
});

it('lets other companies keep a broken fiscal year', function () {
    $company = createWithRules(['fiscal_year_start_month' => 7]);

    CurrentCompany::set($company);
    expect(FiscalYear::query()->sole()->label())->toBe('2026/2027');
});

it('asks a partnership to confirm that no natural person is taxed before it may use a broken year', function (string $legalForm) {
    expect(validationMessages(fn () => createWithRules(['legal_form' => $legalForm, 'fiscal_year_start_month' => 7])))
        ->toContain('ingen fysisk person');

    $company = createWithRules(['legal_form' => $legalForm, 'fiscal_year_start_month' => 7, 'calendar_year_exempt' => true]);

    expect($company->fiscal_year_start_month)->toBe(7)
        ->and(AuditLog::forCompany($company)->where('action', 'company.created')->sole()->payload)
        ->toMatchArray(['calendar_year_exempt_confirmed' => true]);

    expect(createWithRules(['legal_form' => $legalForm, 'fiscal_year_start_month' => 1])->exists)->toBeTrue();
})->with(['HB', 'KB']);

it('has no minimum length for a first fiscal year', function () {
    $company = createWithRules();
    $owner = $company->owner;

    CurrentCompany::set($company);
    app(ChangeFirstFiscalYear::class)($owner, FiscalYear::query()->sole(), '2026-12-01', '2026-12-31');

    expect(FiscalYear::query()->sole()->end_date->toDateString())->toBe('2026-12-31')
        ->and(FiscalYearRules::months(CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-31')))->toBe(1);
});

it('lets a sole trader shorten or extend the first year only up to 31 December', function () {
    $company = createWithRules(['legal_form' => 'EF', 'reporting_framework' => 'K3']);
    $owner = $company->owner;
    CurrentCompany::set($company);
    $year = FiscalYear::query()->sole();

    expect(validationMessages(fn () => app(ChangeFirstFiscalYear::class)($owner, $year, '2026-09-01', '2027-08-31')))
        ->toContain('sluta den 31 december');

    app(ChangeFirstFiscalYear::class)($owner, $year, '2026-09-01', '2026-12-31');
    expect($year->fresh()->label())->toBe('2026');

    app(ChangeFirstFiscalYear::class)($owner, $year, '2025-09-01', '2026-12-31');
    expect($year->fresh()->label())->toBe('2025/2026');
});

it('moves an existing sole trader with a broken year to the calendar year without changing old years', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['legal_form' => 'EF', 'reporting_framework' => 'K1', 'fiscal_year_start_month' => 5])->save();
    CurrentCompany::set($company);
    FiscalYear::create(['start_date' => '2025-05-01', 'end_date' => '2026-04-30']);
    $this->actingAs($owner->fresh());

    Livewire::test(FiscalYears::class)
        ->assertSee('En enskild firma ska ha kalenderår')
        ->assertSee('2026-04-30');

    $next = app(CreateNextFiscalYear::class)($owner->fresh(), $company->fresh());

    expect($next->start_date->toDateString())->toBe('2026-05-01')
        ->and($next->end_date->toDateString())->toBe('2026-12-31')
        ->and(FiscalYear::query()->whereDate('start_date', '2025-05-01')->sole()->end_date->toDateString())->toBe('2026-04-30');

    $after = app(CreateNextFiscalYear::class)($owner->fresh(), $company->fresh());

    expect($after->label())->toBe('2027');
});

it('opens a calendar year when an existing sole trader carries balances forward from a broken year', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['legal_form' => 'EF', 'reporting_framework' => 'K1', 'fiscal_year_start_month' => 5])->save();
    CurrentCompany::set($company);
    $year = FiscalYear::create(['start_date' => '2025-05-01', 'end_date' => '2026-04-30']);
    app(InitialiseBookkeeping::class)($company->fresh(), $owner);
    Bookkeeping::post($owner->fresh(), $company->fresh(), date: '2025-06-01');

    $next = app(CarryForwardBalances::class)($owner->fresh(), $year);

    expect($next->start_date->toDateString())->toBe('2026-05-01')
        ->and($next->end_date->toDateString())->toBe('2026-12-31');
});

it('keeps a sole trader saved with a broken year, warns about it, and refuses a new broken month', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['legal_form' => 'EF', 'reporting_framework' => 'K1', 'fiscal_year_start_month' => 5])->save();
    $this->actingAs($owner->fresh());

    app(UpdateCompanyDetails::class)($owner, $company->fresh(), settingsInput($company->fresh(), ['name' => 'Firma Nytt Namn']));
    expect($company->fresh()->name)->toBe('Firma Nytt Namn');

    Livewire::test(CompanyDetailsForm::class, ['company' => $company->fresh()])
        ->assertSee('En enskild firma ska ha kalenderår som räkenskapsår, men räkenskapsåret är inställt');

    expect(validationMessages(fn () => app(UpdateCompanyDetails::class)($owner, $company->fresh(), settingsInput($company->fresh(), ['fiscal_year_start_month' => 7]))))
        ->toContain('Välj januari');

    app(UpdateCompanyDetails::class)($owner, $company->fresh(), settingsInput($company->fresh(), ['fiscal_year_start_month' => 1]));
    expect($company->fresh()->fiscal_year_start_month)->toBe(1);
});

it('refuses the first-run guide a sole trader with a broken year', function () {
    app()->detectEnvironment(fn () => 'local');

    Livewire::test(FirstRunWizard::class)
        ->set('company_name', 'Firman')
        ->set('org_number', CompanyFactory::organisationNumber()->digits)
        ->set('fiscal_year_start_month', 3)
        ->set('legal_form', 'EF')
        ->assertSet('fiscal_year_start_month', 1)
        ->set('fiscal_year_start_month', 3)
        ->set('reporting_framework', 'K3')
        ->call('toPerson')
        ->assertHasErrors('fiscal_year_start_month')
        ->assertSet('step', 'company');
});

// B4: K1, K2 and K3 per legal form

it('refuses K1 for companies that are not sole traders or non-profit associations', function (string $legalForm, string $message) {
    expect(validationMessages(fn () => createWithRules(['legal_form' => $legalForm, 'reporting_framework' => 'K1', 'framework_confirmed' => true])))
        ->toContain($message);
})->with([
    'aktiebolag' => ['AB', 'kan bara användas av enskilda firmor'],
    'ekonomisk förening' => ['EK_FORENING', 'kan bara användas av enskilda firmor'],
    'stiftelse' => ['STIFTELSE', 'kan bara användas av enskilda firmor'],
    'handelsbolag, not verified' => ['HB', 'inte kontrollerat'],
    'kommanditbolag, not verified' => ['KB', 'inte kontrollerat'],
]);

it('asks for confirmation of the size conditions for K1 and K2, but not for K3', function (string $legalForm, string $framework) {
    expect(validationMessages(fn () => createWithRules(['legal_form' => $legalForm, 'reporting_framework' => $framework])))
        ->toContain('Bekräfta att företaget uppfyller villkoren');

    $company = createWithRules(['legal_form' => $legalForm, 'reporting_framework' => $framework, 'framework_confirmed' => true]);

    expect($company->reporting_framework->value)->toBe($framework)
        ->and(AuditLog::forCompany($company)->where('action', 'company.created')->sole()->payload)
        ->toMatchArray(['reporting_framework' => $framework, 'framework_conditions_confirmed' => true]);
})->with([
    'K1, enskild firma' => ['EF', 'K1'],
    'K1, ideell förening' => ['IDEELL_FORENING', 'K1'],
    'K2, aktiebolag' => ['AB', 'K2'],
    'K2, handelsbolag' => ['HB', 'K2'],
]);

it('always allows K3 without confirmation', function (string $legalForm) {
    expect(createWithRules(['legal_form' => $legalForm, 'reporting_framework' => 'K3'])->reporting_framework->value)->toBe('K3');
})->with(['AB', 'EF', 'HB', 'KB', 'EK_FORENING', 'IDEELL_FORENING', 'STIFTELSE']);

it('shows the conditions in the company form and refuses K1 for an aktiebolag there', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateCompanyForm::class)
        ->assertSee('K2 får bara användas av mindre företag')
        ->assertSee('publika aktiebolag')
        ->assertSee('punkt 1.1B')
        ->set('reporting_framework', 'K1')
        ->assertSee('K1 (förenklat årsbokslut) kan bara användas av enskilda firmor')
        ->set('reporting_framework', 'K3')
        ->assertDontSee('K2 får bara användas av mindre företag');
});

it('never blocks an existing company with a framework that looks wrong, but warns', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['reporting_framework' => 'K1'])->save();
    $this->actingAs($owner->fresh());

    app(UpdateCompanyDetails::class)($owner, $company->fresh(), settingsInput($company->fresh(), ['name' => 'Kvar AB']));

    expect($company->fresh()->name)->toBe('Kvar AB')
        ->and($company->fresh()->reporting_framework->value)->toBe('K1');

    Livewire::test(CompanyDetailsForm::class, ['company' => $company->fresh()])
        ->assertSee('Regelverket K1 ser inte ut att passa företagsformen');
});

it('checks a framework that is changed in the settings', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['reporting_framework' => 'K3'])->save();

    expect(validationMessages(fn () => app(UpdateCompanyDetails::class)($owner, $company->fresh(), settingsInput($company->fresh(), ['reporting_framework' => 'K1', 'framework_confirmed' => true]))))
        ->toContain('kan bara användas av enskilda firmor');

    expect(validationMessages(fn () => app(UpdateCompanyDetails::class)($owner, $company->fresh(), settingsInput($company->fresh(), ['reporting_framework' => 'K2']))))
        ->toContain('Bekräfta att företaget uppfyller villkoren');

    app(UpdateCompanyDetails::class)($owner, $company->fresh(), settingsInput($company->fresh(), ['reporting_framework' => 'K2', 'framework_confirmed' => true]));

    expect($company->fresh()->reporting_framework->value)->toBe('K2')
        ->and(AuditLog::forCompany($company)->where('action', 'company.updated')->latest('id')->first()->payload)
        ->toMatchArray(['framework_conditions_confirmed' => true]);
});

it('offers the confirmation in the settings form when the framework changes', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['reporting_framework' => 'K3'])->save();
    $this->actingAs($owner->fresh());

    Livewire::test(CompanyDetailsForm::class, ['company' => $company->fresh()])
        ->assertDontSee('K2 får bara användas av mindre företag')
        ->set('state.reporting_framework', 'K2')
        ->assertSee('K2 får bara användas av mindre företag')
        ->call('save')
        ->assertHasErrors('framework_confirmed')
        ->set('state.framework_confirmed', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($company->fresh()->reporting_framework->value)->toBe('K2');
});
