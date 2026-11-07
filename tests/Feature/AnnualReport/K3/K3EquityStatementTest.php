<?php

declare(strict_types=1);

use App\Actions\Accounting\AnalyseSieFile;
use App\Actions\Accounting\CarryForwardBalances;
use App\Actions\Accounting\ConfirmSieImport;
use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\K2Schedule;
use App\Domain\AnnualReport\K3EquityStatement;
use App\Domain\AnnualReport\K3EquityStatementRefused;
use App\Domain\AnnualReport\K3Schedule;
use App\Domain\AnnualReport\PrintedFigures;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\S1Fixture;

/*
 * S1-P7 (ADR-059 A.4): the K3 6.3 specification of changes in equity, from
 * F-S1-a to the öre, and the refusals: a change that fits none of the rows
 * of 6.3 blocks the report with the accounts behind it and is never printed
 * as "Övriga förändringar".
 */

/** expected.json changes_in_equity "rad" => K3EquityStatement row key */
const F_S1_A_EQUITY_ROWS = [
    'Belopp vid årets ingång' => 'opening',
    'Omföring av föregående års resultat enligt årsstämmans beslut' => 'prior_result_transfer',
    'Utdelning (transaktion med ägare)' => 'dividend',
    'Årets resultat' => 'year_result',
    'Belopp vid årets utgång' => 'closing',
];

/** expected.json column => K3Schedule key */
const F_S1_A_EQUITY_COLUMNS = [
    'aktiekapital' => 'share_capital',
    'balanserat_resultat' => 'retained',
    'arets_resultat' => 'year_result',
];

/**
 * Posts a balanced voucher in the fixture company: rows as [account, debit öre, credit öre].
 *
 * @param  list<array{0: int, 1: int, 2: int}>  $rows
 */
function postEquityVoucher(S1Fixture $fixture, string $date, string $description, array $rows): Voucher
{
    foreach ($rows as [$number]) {
        if (! Account::query()->where('number', $number)->exists()) {
            Account::create(['number' => $number, 'name' => "Konto {$number}", 'type' => AccountType::forNumber($number), 'is_active' => true]);
        }
    }

    $draft = app(SaveDraftVoucher::class)($fixture->owner, $fixture->company, [
        'voucher_date' => $date,
        'description' => $description,
        'series' => 'A',
        'rows' => array_map(fn (array $row): array => ['account' => $row[0], 'debit_ore' => $row[1], 'credit_ore' => $row[2]], $rows),
    ]);

    return app(PostVoucher::class)($fixture->owner, $draft);
}

function k3Equity(FiscalYear $year): K3EquityStatement
{
    return K3EquityStatement::of(AnnualAccounts::for($year, K3Schedule::class));
}

/**
 * The statement's rows by key: amounts per column, with the closing check per column.
 *
 * @return array<string, array<string, int>>
 */
function k3EquityAmounts(K3EquityStatement $statement): array
{
    return collect($statement->rows())->mapWithKeys(fn (array $row): array => [$row['key'] => $row['amounts']])->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    $this->fixture = S1Fixture::load('f-s1-a');
    CurrentCompany::set($this->fixture->company);
    $this->actingAs($this->fixture->owner);
});

it('S1-R8.3 gives F-S1-a\'s 2025 and 2026 equity statements to the öre, row for row and column for column', function () {
    foreach ([2025, 2026] as $year) {
        $statement = k3Equity($this->fixture->years[$year]);
        $rows = $statement->rows();

        expect($statement->refusal())->toBeNull()
            ->and(array_column($rows, 'key'))->toBe(array_values(F_S1_A_EQUITY_ROWS), "{$year} rows")
            ->and(array_keys($rows[0]['amounts']))->toBe(array_values(F_S1_A_EQUITY_COLUMNS), "{$year} columns");

        $byKey = collect($rows)->keyBy('key');

        foreach ($this->fixture->expected['changes_in_equity'][(string) $year] as $expected) {
            $row = $byKey[F_S1_A_EQUITY_ROWS[$expected['rad']]];

            foreach (F_S1_A_EQUITY_COLUMNS as $fixtureColumn => $column) {
                expect($row['amounts'][$column])->toBe($expected[$fixtureColumn], "{$year} {$expected['rad']} {$fixtureColumn}");
            }

            expect($row['total'])->toBe($expected['summa'], "{$year} {$expected['rad']} summa");
        }

        expect(collect($rows)->pluck('label')->all())->not->toContain('Övriga förändringar');
    }
});

it('S1-R8.3 is the equity specification of AnnualAccounts under K3, while K2 keeps its own', function () {
    $year = $this->fixture->years[2026];

    expect(AnnualAccounts::for($year, K3Schedule::class)->equityChanges())->toBe(k3Equity($year)->rows())
        ->and(array_column(AnnualAccounts::for($year, K2Schedule::class)->equityChanges(), 'key'))->not->toContain('prior_result_transfer');
});

it('S1-R8.3 prints F-S1-a\'s statements in whole kronor without adding a row, and every column adds up', function () {
    foreach ([2025, 2026] as $year) {
        $accounts = AnnualAccounts::for($this->fixture->years[$year], K3Schedule::class);
        $printed = PrintedFigures::apply([
            'income' => $accounts->incomeStatement(),
            'hasPreviousIncome' => $accounts->previousYear !== null,
            'balance' => $accounts->balanceSheet(),
            'multiYear' => $multiYear = $accounts->multiYear(),
            'netSalesVariations' => $accounts->netSalesVariations($multiYear),
            'equityChanges' => $accounts->equityChanges(),
            'disposition' => ['dividend' => 0],
            'fixedAssets' => $accounts->fixedAssetNotes(),
            'pledged' => [],
            'contingent' => null,
        ], K3Schedule::class);
        $rows = collect($printed['equityChanges'])->keyBy('key');

        expect($rows->keys()->all())->toBe(array_values(F_S1_A_EQUITY_ROWS));

        foreach ($rows['opening']['amounts'] as $column => $opening) {
            $changes = $rows->except(['opening', 'closing'])->sum(fn (array $row): int => $row['amounts'][$column]);
            expect($opening + $changes)->toBe($rows['closing']['amounts'][$column], "{$year} {$column}");
        }

        foreach ($rows as $row) {
            foreach ($row['amounts'] as $amount) {
                expect($amount % 100)->toBe(0);
            }
        }
    }

    // 2026: the result 3 487 001,84 kr prints as 3 487 002 kr, the transfer of 1 952 029,62 kr as 1 952 030 kr.
    $accounts = AnnualAccounts::for($this->fixture->years[2026], K3Schedule::class);
    $printed = collect(PrintedFigures::apply([
        'income' => $accounts->incomeStatement(), 'hasPreviousIncome' => true, 'balance' => $accounts->balanceSheet(),
        'multiYear' => [], 'netSalesVariations' => [], 'equityChanges' => $accounts->equityChanges(),
        'disposition' => ['dividend' => 0], 'fixedAssets' => $accounts->fixedAssetNotes(), 'pledged' => [], 'contingent' => null,
    ], K3Schedule::class)['equityChanges'])->keyBy('key');

    expect($printed['year_result']['amounts']['year_result'])->toBe(348_700_200)
        ->and($printed['prior_result_transfer']['amounts'])->toBe(['share_capital' => 0, 'retained' => 195_203_000, 'year_result' => -195_203_000])
        ->and($printed['closing']['amounts']['retained'])->toBe(2_025_856_800);
});

it('S1-R8.3 blocks a change that fits no row of K3 6.3, names its accounts and voucher, and never prints "Övriga förändringar"', function () {
    postEquityVoucher($this->fixture, '2026-06-30', 'Okänd insättning direkt mot eget kapital', [[1930, 10_000_000, 0], [2091, 0, 10_000_000]]);

    $statement = k3Equity($this->fixture->years[2026]);

    expect($statement->refusal())->toContain('2091')
        ->and($statement->refusal())->toContain('A55')
        ->and($statement->residualAccounts())->toBe([2091])
        ->and(fn () => $statement->rows())->toThrow(K3EquityStatementRefused::class)
        ->and(fn () => AnnualAccounts::for($this->fixture->years[2026], K3Schedule::class)->equityChanges())->toThrow(K3EquityStatementRefused::class);

    // K2 keeps showing the same change as "Övriga förändringar" (ADR-010 #90), unchanged.
    expect(collect(AnnualAccounts::for($this->fixture->years[2026], K2Schedule::class)->equityChanges())->pluck('label'))->toContain('Övriga förändringar');
});

it('S1-R8.3 blocks an opening balance that differs from last year\'s closing balance, with the account', function () {
    $account = Account::query()->where('number', 2091)->value('id');
    $cash = Account::query()->where('number', 1930)->value('id');
    $year = $this->fixture->years[2026];

    foreach ([[$account, -10_000], [$cash, 10_000]] as [$id, $delta]) {
        $balance = OpeningBalance::query()->where('fiscal_year_id', $year->id)->where('account_id', $id)->firstOrFail();
        $balance->forceFill(['amount_ore' => $balance->amount_ore->ore + $delta])->save();
    }

    $statement = k3Equity($year);

    expect($statement->residualAccounts())->toBe([2091])
        ->and($statement->refusal())->toContain('2091')
        ->and($statement->refusal())->toContain('ingående balans');
});

it('S1-R10 shows transfers to and from the fund for development expenses as their own 6.3 e rows', function () {
    postEquityVoucher($this->fixture, '2026-12-31', 'Avsättning till fond för utvecklingsutgifter', [[2091, 5_000_000, 0], [2089, 0, 5_000_000]]);
    postEquityVoucher($this->fixture, '2026-12-31', 'Fonden minskas med årets avskrivning (ÅRL 4 kap. 8 §)', [[2089, 1_000_000, 0], [2091, 0, 1_000_000]]);

    $statement = k3Equity($this->fixture->years[2026]);
    $amounts = k3EquityAmounts($statement);

    expect($statement->refusal())->toBeNull()
        ->and(array_keys($amounts['opening']))->toBe(['share_capital', 'development_fund', 'retained', 'year_result'])
        ->and($amounts['to_development_fund'])->toBe(['share_capital' => 0, 'development_fund' => 5_000_000, 'retained' => -5_000_000, 'year_result' => 0])
        ->and($amounts['from_development_fund'])->toBe(['share_capital' => 0, 'development_fund' => -1_000_000, 'retained' => 1_000_000, 'year_result' => 0])
        ->and($amounts['closing']['development_fund'])->toBe(4_000_000)
        ->and(K3EquityStatement::POINTS['to_development_fund'])->toBe('e');
});

it('S1-R8.3 shows shareholder contributions, their repayment and a new issue as separate owner transactions (6.3 d)', function () {
    postEquityVoucher($this->fixture, '2026-03-01', 'Erhållet aktieägartillskott', [[1930, 20_000_000, 0], [2093, 0, 20_000_000]]);
    postEquityVoucher($this->fixture, '2026-09-01', 'Beslut om återbetalning av aktieägartillskott', [[2093, 5_000_000, 0], [2893, 0, 5_000_000]]);
    postEquityVoucher($this->fixture, '2026-10-01', 'Nyemission', [[1930, 30_000_000, 0], [2081, 0, 10_000_000], [2097, 0, 20_000_000]]);

    $statement = k3Equity($this->fixture->years[2026]);
    $amounts = k3EquityAmounts($statement);

    expect($statement->refusal())->toBeNull()
        ->and(array_keys($amounts))->toBe(['opening', 'prior_result_transfer', 'dividend', 'shareholder_contribution', 'contribution_repayment', 'new_issue', 'year_result', 'closing'])
        ->and($amounts['shareholder_contribution']['retained'])->toBe(20_000_000)
        ->and($amounts['contribution_repayment']['retained'])->toBe(-5_000_000)
        ->and($amounts['new_issue'])->toMatchArray(['share_capital' => 10_000_000, 'free_share_premium' => 20_000_000, 'retained' => 0]);

    foreach (['shareholder_contribution', 'contribution_repayment', 'new_issue', 'dividend'] as $key) {
        expect(K3EquityStatement::POINTS[$key])->toBe('d');
    }
});

it('S1-R8.3 counts the year\'s result booked to 2099 (8999 against 2099) in row a, not as a change of its own', function () {
    $before = k3Equity($this->fixture->years[2026])->rows();
    postEquityVoucher($this->fixture, '2026-12-31', 'Årets resultat 2026', [[8999, 348_700_184, 0], [2099, 0, 348_700_184]]);

    $after = k3Equity($this->fixture->years[2026]);

    expect($after->refusal())->toBeNull()
        ->and($after->rows())->toBe($before);
});

it('S1-R8.3 refuses a dividend charged to bound equity', function () {
    postEquityVoucher($this->fixture, '2026-06-01', 'Utdelning ur aktiekapitalet', [[2081, 10_000_000, 0], [2898, 0, 10_000_000]]);

    expect(k3Equity($this->fixture->years[2026])->residualAccounts())->toBe([2081]);
});

it('S1-R8.3 reads the equity vouchers with one query, however many there are', function () {
    $count = function (): int {
        $accounts = AnnualAccounts::for($this->fixture->years[2026], K3Schedule::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        K3EquityStatement::of($accounts)->rows();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $short = $count();

    foreach (range(1, 8) as $month) {
        postEquityVoucher($this->fixture, sprintf('2026-%02d-20', $month), 'Aktieägartillskott', [[1930, 100_000, 0], [2093, 0, 100_000]]);
    }

    expect($short)->toBe(1)
        ->and($count())->toBe($short);

    // With fresh planner statistics the query still finishes at once (an IN subquery
    // planned as a nested loop semi join did not; S1-P7 review). ANALYZE only updates
    // statistics and may run inside the test's transaction.
    DB::statement('ANALYZE vouchers, voucher_rows, accounts');
    $started = microtime(true);
    expect($count())->toBe(1)
        ->and(microtime(true) - $started)->toBeLessThan(10.0);
});

it('S1-R8.3 shows a loss year\'s disposition with a dividend (2099 credit, 2098 debit, 2898 credit) as a transfer and a dividend', function () {
    postEquityVoucher($this->fixture, '2026-05-20', 'Resultatdisposition: förlust och utdelning', [[2099, 0, 1_000_000], [2098, 1_500_000, 0], [2898, 0, 500_000]]);

    $statement = k3Equity($this->fixture->years[2026]);
    $amounts = k3EquityAmounts($statement);

    expect($statement->refusal())->toBeNull()
        ->and($amounts['dividend'])->toBe(['share_capital' => 0, 'retained' => -150_500_000, 'year_result' => 0])
        ->and($amounts['prior_result_transfer']['year_result'])->toBe(-195_202_962);
});

it('S1-R8.3 nets a correction that re-books a contribution with its original: 100 000 corrected to 120 000 is a contribution of 120 000', function () {
    $original = postEquityVoucher($this->fixture, '2026-03-01', 'Aktieägartillskott', [[1930, 10_000_000, 0], [2093, 0, 10_000_000]]);
    app(CorrectVoucher::class)($this->fixture->owner, $original, 'Fel belopp', '2026-03-02', [
        ['account' => 1930, 'debit_ore' => 12_000_000, 'credit_ore' => 0],
        ['account' => 2093, 'debit_ore' => 0, 'credit_ore' => 12_000_000],
    ]);

    $statement = k3Equity($this->fixture->years[2026]);
    $amounts = k3EquityAmounts($statement);

    expect($statement->refusal())->toBeNull()
        ->and($amounts['shareholder_contribution']['retained'])->toBe(12_000_000)
        ->and($amounts)->not->toHaveKey('contribution_repayment');
});

it('S1-R8.3 drops a contribution that a correction reverses in full', function () {
    $before = k3Equity($this->fixture->years[2026])->rows();
    $original = postEquityVoucher($this->fixture, '2026-03-01', 'Aktieägartillskott', [[1930, 10_000_000, 0], [2093, 0, 10_000_000]]);
    app(CorrectVoucher::class)($this->fixture->owner, $original, 'Bokades i fel företag', '2026-03-02');

    expect(k3Equity($this->fixture->years[2026])->rows())->toBe($before);
});

it('S1-R8.3 keeps a dividend corrected up or down on the dividend row', function (int $corrected) {
    $original = postEquityVoucher($this->fixture, '2026-05-20', 'Utdelning', [[2091, 4_000_000, 0], [2898, 0, 4_000_000]]);
    app(CorrectVoucher::class)($this->fixture->owner, $original, 'Ändrat belopp', '2026-05-21', [
        ['account' => 2091, 'debit_ore' => $corrected, 'credit_ore' => 0],
        ['account' => 2898, 'debit_ore' => 0, 'credit_ore' => $corrected],
    ]);

    $statement = k3Equity($this->fixture->years[2026]);
    $amounts = k3EquityAmounts($statement);

    expect($statement->refusal())->toBeNull()
        ->and($amounts['dividend']['retained'])->toBe(-150_000_000 - $corrected)
        ->and(array_keys($amounts))->toBe(['opening', 'prior_result_transfer', 'dividend', 'year_result', 'closing']);
})->with([
    'up from 40 000 to 50 000' => 5_000_000,
    'down from 40 000 to 30 000' => 3_000_000,
]);

it('S1-R8.3 lets an expense booked against 2091 by mistake drop out once it is corrected to an expense account', function () {
    $original = postEquityVoucher($this->fixture, '2026-03-01', 'Fel konto', [[2091, 50_000, 0], [1930, 0, 50_000]]);

    expect(k3Equity($this->fixture->years[2026])->residualAccounts())->toBe([2091]);

    app(CorrectVoucher::class)($this->fixture->owner, $original, 'Fel konto', '2026-03-02', [
        ['account' => 6110, 'debit_ore' => 50_000, 'credit_ore' => 0],
        ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => 50_000],
    ]);

    expect(k3Equity($this->fixture->years[2026])->refusal())->toBeNull();
});

it('S1-R8.3 refuses a debit on 2099 against a liability and names 2099', function () {
    postEquityVoucher($this->fixture, '2026-05-20', 'Okänd', [[2099, 100_000, 0], [2440, 0, 100_000]]);

    $statement = k3Equity($this->fixture->years[2026]);

    expect($statement->residualAccounts())->toBe([2099])
        ->and($statement->refusal())->toContain('konto 2099')
        ->and($statement->refusal())->not->toContain('konto  ');
});

it('S1-R8.3 refuses a dividend voucher whose counter accounts are not 2898 alone with the same amount', function () {
    postEquityVoucher($this->fixture, '2026-05-20', 'Blandad', [[2091, 100_000, 0], [2898, 0, 40_000], [2440, 0, 60_000]]);

    expect(k3Equity($this->fixture->years[2026])->residualAccounts())->toBe([2091]);
});

it('S1-R8.3 refuses own shares (2094) and a repayment of share capital (2081 against 2898)', function (array $rows, int $account) {
    postEquityVoucher($this->fixture, '2026-06-01', 'Ägartransaktion utan rad', $rows);

    expect(k3Equity($this->fixture->years[2026])->residualAccounts())->toBe([$account]);
})->with([
    'own shares bought back' => [[[2094, 1_000_000, 0], [1930, 0, 1_000_000]], 2094],
    'share capital repaid' => [[[2081, 1_000_000, 0], [2898, 0, 1_000_000]], 2081],
]);

it('S1-R8.3 starts the first year in the books (no previous year) from the opening balances', function () {
    $accounts = AnnualAccounts::for($this->fixture->years[2025], K3Schedule::class);
    $opening = k3EquityAmounts(K3EquityStatement::of($accounts))['opening'];

    expect($accounts->previousYear)->toBeNull()
        ->and($opening)->toBe([
            'share_capital' => -$accounts->openingOf(2081, 2081),
            'retained' => -$accounts->openingOf(2091, 2091),
            'year_result' => -$accounts->openingOf(2099, 2099),
        ]);
});

it('S1-R8.3 specifies a year imported from SIE with opening balances and the disposition as an imported voucher', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
    [$owner, $company] = Bookkeeping::company();
    $company->forceFill(['org_number' => '556334-3689'])->save();
    CurrentCompany::set($company);
    $this->actingAs($owner);

    $lines = [
        '#FLAGGA 0', '#FORMAT PC8', '#SIETYP 4', '#ORGNR 556334-3689', '#RAR 0 20260101 20261231',
        '#KONTO 1930 "Företagskonto"', '#KONTO 2081 "Aktiekapital"', '#KONTO 2091 "Balanserad vinst eller förlust"',
        '#KONTO 2099 "Årets resultat"', '#KONTO 2898 "Outtagen vinstutdelning"',
        '#IB 0 1930 1000000.00', '#IB 0 2081 -50000.00', '#IB 0 2091 -750000.00', '#IB 0 2099 -200000.00',
        "#VER A 1 20260520 \"Resultatdisposition\"\r\n{\r\n#TRANS 2099 {} 200000.00\r\n#TRANS 2091 {} -150000.00\r\n#TRANS 2898 {} -50000.00\r\n}",
    ];
    $file = UploadedFile::fake()->createWithContent('bokforing.se', (string) iconv('UTF-8', 'CP437', implode("\r\n", $lines)."\r\n"));
    $import = app(AnalyseSieFile::class)($owner, $company, $file);
    app(ConfirmSieImport::class)($owner, $import);

    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $accounts = AnnualAccounts::for($year, K3Schedule::class);
    $statement = K3EquityStatement::of($accounts);
    $amounts = k3EquityAmounts($statement);

    expect(Voucher::query()->where('source', 'IMPORT_SIE')->count())->toBe(1)
        ->and($accounts->previousYear)->toBeNull()
        ->and($statement->refusal())->toBeNull()
        ->and($amounts['opening'])->toBe(['share_capital' => 5_000_000, 'retained' => 75_000_000, 'year_result' => 20_000_000])
        ->and($amounts['prior_result_transfer'])->toBe(['share_capital' => 0, 'retained' => 20_000_000, 'year_result' => -20_000_000])
        ->and($amounts['dividend'])->toBe(['share_capital' => 0, 'retained' => -5_000_000, 'year_result' => 0])
        ->and($amounts['closing']['retained'])->toBe(90_000_000);
});

it('S1-R8.3 refuses a correction in 2026 of an equity voucher from 2025 and names both vouchers (row b, S1-P12)', function (array $rows) {
    $original = postEquityVoucher($this->fixture, '2025-06-01', 'Ägartransaktion 2025', $rows);
    app(CarryForwardBalances::class)($this->fixture->owner, $this->fixture->years[2025]->fresh());
    $correction = app(CorrectVoucher::class)($this->fixture->owner, $original, 'Fel', '2026-03-02');

    $statement = k3Equity($this->fixture->years[2026]);

    expect($statement->refusal())->toContain("rättelseverifikation {$correction->reference()} rättar verifikation {$original->reference()} från räkenskapsåret 2025")
        ->and($statement->residualAccounts())->not->toBe([])
        ->and(fn () => $statement->rows())->toThrow(K3EquityStatementRefused::class);
})->with([
    'a contribution reversed' => [[[1930, 10_000_000, 0], [2093, 0, 10_000_000]]],
    'a dividend reversed' => [[[2091, 40_000, 0], [2898, 0, 40_000]]],
]);

it('S1-R8.3 classifies a correction that moves an amount of the same year onto equity by what it books', function () {
    Account::create(['number' => 2093, 'name' => 'Erhållna aktieägartillskott', 'type' => AccountType::forNumber(2093), 'is_active' => true]);
    $original = postEquityVoucher($this->fixture, '2026-03-01', 'Intäkt', [[1930, 10_000_000, 0], [3990, 0, 10_000_000]]);
    app(CorrectVoucher::class)($this->fixture->owner, $original, 'Var tillskott', '2026-03-02', [
        ['account' => 1930, 'debit_ore' => 10_000_000, 'credit_ore' => 0],
        ['account' => 2093, 'debit_ore' => 0, 'credit_ore' => 10_000_000],
    ]);

    $statement = k3Equity($this->fixture->years[2026]);

    expect($statement->refusal())->toBeNull()
        ->and(k3EquityAmounts($statement)['shareholder_contribution']['retained'])->toBe(10_000_000);
});
