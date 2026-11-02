<?php

declare(strict_types=1);

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Company\CreateCompany;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\VoucherSeries;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;

function createCompanyWith(array $overrides = []): Company
{
    return app(CreateCompany::class)->create(User::factory()->create(), [
        'name' => 'Bokföring AB',
        'org_number' => CompanyFactory::organisationNumber()->digits,
        'legal_form' => 'AB',
        'fiscal_year_start_month' => 1,
        'accounting_method' => 'FAKTURAMETODEN',
        'vat_registered' => true,
        'vat_period' => 'QUARTERLY',
        'reporting_framework' => 'K2',
        'framework_confirmed' => true,
        'f_skatt' => true,
        'employer_registered' => false,
        ...$overrides,
    ]);
}

it('gives a new limited company the K2 chart of accounts', function () {
    $company = createCompanyWith();

    CurrentCompany::set($company);
    $numbers = Account::query()->pluck('number')->all();

    expect($numbers)->toHaveCount(108)
        ->toContain(1930, 2081, 2099, 2611, 2641, 2650, 3001, 6570, 8010, 8999)
        ->not->toContain(2010);

    expect(Account::query()->where('number', 1930)->sole())
        ->name->toBe('Företagskonto/checkkonto/affärskonto')
        ->type->value->toBe('ASSET')
        ->is_active->toBeTrue();
});

it('gives a K1 company sole-trader equity accounts', function () {
    $company = createCompanyWith(['legal_form' => 'EF', 'reporting_framework' => 'K1']);

    CurrentCompany::set($company);
    $numbers = Account::query()->pluck('number')->all();

    expect($numbers)->toContain(2010, 2013, 2018, 2019)->not->toContain(2081, 2099);
});

it('creates the default voucher series with A as the default', function () {
    $company = createCompanyWith();

    CurrentCompany::set($company);

    expect(VoucherSeries::query()->orderBy('code')->pluck('name', 'code')->all())->toBe([
        'A' => 'Manuell',
        'B' => 'Kundfakturor',
        'C' => 'Kundinbetalningar',
        'D' => 'Leverantörsfakturor',
        'E' => 'Leverantörsbetalningar',
        'I' => 'Ingående balanser/import',
        'L' => 'Lön',
        'M' => 'Moms',
    ])->and(VoucherSeries::query()->where('is_default', true)->pluck('code')->all())->toBe(['A']);
});

it('creates the fiscal year that is running now', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29'));

    $calendar = createCompanyWith();
    $broken = createCompanyWith(['fiscal_year_start_month' => 7]);

    $year = fn (Company $company) => CurrentCompany::run($company, fn () => FiscalYear::query()->sole());

    expect($year($calendar)->start_date->toDateString())->toBe('2026-01-01')
        ->and($year($calendar)->end_date->toDateString())->toBe('2026-12-31')
        ->and($year($calendar)->isOpen())->toBeTrue()
        ->and($year($broken)->start_date->toDateString())->toBe('2026-07-01')
        ->and($year($broken)->end_date->toDateString())->toBe('2027-06-30');
});

it('records the setup in the audit log', function () {
    $company = createCompanyWith();

    expect(AuditLog::forCompany($company)->where('action', 'company.bookkeeping_initialised')->sole()->payload)
        ->toMatchArray(['accounts' => 108, 'voucher_series' => 8, 'bas_version' => 'BAS 2026']);
});

it('only adds what is missing when run again', function () {
    $company = createCompanyWith();

    app(InitialiseBookkeeping::class)($company);

    CurrentCompany::set($company);

    expect(Account::query()->count())->toBe(108)
        ->and(VoucherSeries::query()->count())->toBe(8)
        ->and(FiscalYear::query()->count())->toBe(1)
        ->and(AuditLog::forCompany($company)->where('action', 'company.bookkeeping_initialised')->count())->toBe(1);
});

it('sets up companies that existed before the chart of accounts', function () {
    $company = Company::factory()->create();

    app(InitialiseBookkeeping::class)($company);

    CurrentCompany::set($company);

    expect(Account::query()->count())->toBe(108)
        ->and(FiscalYear::query()->count())->toBe(1);
});
