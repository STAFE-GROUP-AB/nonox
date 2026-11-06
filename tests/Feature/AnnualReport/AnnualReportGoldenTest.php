<?php

declare(strict_types=1);

/*
 * ADR-059 stage F.1: the K2 annual report is frozen by golden files, so the
 * K3 work cannot change it by accident. The rendered HTML of
 * AnnualReportDocument::html() and the iXBRL of K2AnnualReportIxbrl::render()
 * are compared byte for byte with tests/Fixtures/annual-report/.
 *
 * Two synthetic companies (no real data): a profitable aktiebolag over two
 * years with entered notes and pledged assets (the fixture of
 * IxbrlAnnualReportTest), and a loss-making one with öre and no previous
 * year (the fixture of IxbrlRoundingTest). Every value that could vary
 * between runs (the company's name, organisation number, the user) is fixed
 * here; the clock is frozen.
 *
 * To rewrite the files after a deliberate change to the K2 report, run the
 * test with NONOX_UPDATE_GOLDEN=1 and review the diff before committing.
 */

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\Banking\BankReconciliations;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Company\VatPeriod;
use App\Models\Account;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Support\Ixbrl\K2AnnualReportIxbrl;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    // The output does not depend on an installed taxonomy; the check only refuses.
    config(['services.bolagsverket.taxonomy_dir' => null]);
});

/** @return array{0: User, 1: Company} */
function goldenReportCompany(string $orgNumber, string $legalName): array
{
    [$owner, $company] = Bookkeeping::company();
    $owner->forceFill(['name' => 'Anna Andersson'])->save();
    $company->forceFill(['vat_period' => VatPeriod::None, 'org_number' => $orgNumber, 'legal_name' => $legalName, 'name' => $legalName, 'city' => 'Exempelstad'])->save();
    CurrentCompany::set($company);
    test()->actingAs($owner);

    return [$owner, $company];
}

/** @return array{0: Company, 1: FiscalYear, 2: AnnualReport} */
function goldenProfitableReport(): array
{
    test()->travelTo(CarbonImmutable::parse('2028-03-10 10:00:00'));
    [$owner, $company] = goldenReportCompany('556000-0001', 'Exempelbolaget AB');
    $previous = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $closing = app(YearEndClosing::class);

    foreach ([1930 => 7_500_000, 2081 => -2_500_000, 2091 => -5_000_000] as $number => $ore) {
        $account = Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
        OpeningBalance::create(['fiscal_year_id' => $previous->id, 'account_id' => $account->id, 'amount_ore' => $ore]);
    }

    Bookkeeping::post($owner, $company, 10_000_000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($owner, $company, 2_000_000, 5010, 1930, '2026-04-01');
    app(BankReconciliations::class)->save($owner, $company, Account::query()->where('number', 1930)->firstOrFail(), '2026-12-31', '155 000', [], null);

    foreach (array_keys(ClosingChecklist::MANUAL) as $key) {
        $closing->check($owner, $previous, $key, true);
    }

    $closing->bookTax($owner, $previous, '16 480');
    $closing->bookResult($owner, $previous);
    $closing->close($owner, $previous);
    $closing->dispose($owner, $previous, '2027-05-20', '30 000');

    foreach ([1110 => 'Byggnader', 1119 => 'Ackumulerade avskrivningar på byggnader', 2350 => 'Skulder till kreditinstitut', 7820 => 'Avskrivningar på byggnader', 8410 => 'Räntekostnader'] as $number => $name) {
        Account::query()->firstOrCreate(['number' => $number], ['name' => $name, 'type' => AccountType::forNumber($number)]);
    }

    Bookkeeping::post($owner, $company, 15_000_000, 1930, 3001, '2027-03-01');
    Bookkeeping::post($owner, $company, 2_000_000, 5010, 1930, '2027-04-01');
    Bookkeeping::post($owner, $company, 500_000, 5010, 2440, '2027-12-15');
    Bookkeeping::post($owner, $company, 3_000_000, 7010, 1930, '2027-06-25');
    Bookkeeping::post($owner, $company, 20_000_000, 1110, 2350, '2027-02-01');
    Bookkeeping::post($owner, $company, 400_000, 7820, 1119, '2027-12-31');
    Bookkeeping::post($owner, $company, 150_000, 8410, 1930, '2027-12-31');

    $year = FiscalYear::query()->whereDate('start_date', '2027-01-01')->firstOrFail();
    $closing->bookTax($owner, $year, '10 000');

    $report = app(SaveAnnualReport::class)($owner, $year, [
        'business_description' => "Bolaget driver ett kafé i Exempelstad.\nSätet är i Exempelstad.",
        'significant_events' => 'Bolaget köpte sin lokal.',
        'net_sales_comment' => 'Kaféet hade öppet hela året & fick en ny stor kund.',
        'dividend' => '20 000',
        'average_employees' => '1',
        'signing_place' => 'Exempelstad',
        'signing_date' => '2028-03-01',
        'signatories' => "Anna Andersson, Styrelseledamot\nBo Berg, Verkställande direktör",
        'adoption_date' => '2028-04-15',
        'certifier_name' => 'Anna Andersson',
        'certifier_role' => 'Styrelseledamot',
        'pledged' => ['company_mortgage' => '100 000'],
        'contingent_liabilities' => '5 000',
        'contingent_liabilities_text' => 'Borgen för en leverantörs lån',
        'events_after' => 'Bolaget har öppnat ett andra kafé.',
    ]);

    return [$company, $year, $report];
}

/** @return array{0: Company, 1: FiscalYear, 2: AnnualReport} */
function goldenLossReport(): array
{
    test()->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$owner, $company] = goldenReportCompany('556000-0002', 'Förlustbolaget AB');
    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    foreach ([1930 => 5_000_050, 2081 => -2_500_000, 2091 => -2_500_050] as $number => $ore) {
        $account = Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
        OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => $account->id, 'amount_ore' => $ore]);
    }

    Account::query()->firstOrCreate(['number' => 2350], ['name' => 'Skulder till kreditinstitut', 'type' => AccountType::forNumber(2350)]);
    Bookkeeping::post($owner, $company, 10_000_025, 1930, 2350, '2026-02-01');
    Bookkeeping::post($owner, $company, 3_000_040, 1930, 3001, '2026-03-01');
    Bookkeeping::post($owner, $company, 9_000_030, 5010, 1930, '2026-04-01');
    Bookkeeping::post($owner, $company, 100_049, 5010, 2440, '2026-11-01');
    Bookkeeping::post($owner, $company, 1_234_567, 7010, 1930, '2026-06-25');

    $report = app(SaveAnnualReport::class)($owner, $year, [
        'business_description' => 'Bolaget säljer kaffe i Exempelstad.',
        'average_employees' => '0',
        'adoption_date' => '2027-05-01',
    ]);

    return [$company, $year, $report];
}

function goldenReportCompare(string $name, string $actual): void
{
    $path = base_path("tests/Fixtures/annual-report/{$name}");

    if (getenv('NONOX_UPDATE_GOLDEN') === '1') {
        file_put_contents($path, $actual);
    }

    expect(is_file($path))->toBeTrue("The golden file {$name} is missing. Run the test with NONOX_UPDATE_GOLDEN=1 and review it.")
        ->and($actual)->toBe((string) file_get_contents($path), "The K2 annual report output differs from {$name}.");
}

it('renders the K2 annual report of a profitable company exactly as the golden files', function () {
    [$company, $year, $report] = goldenProfitableReport();

    goldenReportCompare('k2-profitable.html', AnnualReportDocument::html($company, $year, $report));
    goldenReportCompare('k2-profitable.xhtml', K2AnnualReportIxbrl::render($company, $year, $report));
});

it('renders the K2 annual report of a loss-making company with öre exactly as the golden files', function () {
    [$company, $year, $report] = goldenLossReport();

    goldenReportCompare('k2-loss.html', AnnualReportDocument::html($company, $year, $report));
    goldenReportCompare('k2-loss.xhtml', K2AnnualReportIxbrl::render($company, $year, $report));
});
