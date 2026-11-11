<?php

declare(strict_types=1);

use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\K2Schedule;
use App\Domain\AnnualReport\K3Schedule;
use App\Domain\AnnualReport\PrintedFigures;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\S1Fixture;

/*
 * S1-P6 (ADR-059 A.4): F-S1-a's K3 income statement and balance sheet, to the
 * öre and as printed (whole kronor, every sum the sum of the rounded lines).
 * The fixture is frozen; its keys are mapped to K3Schedule's line keys here.
 */

/** expected.json income statement key => K3Schedule key */
const F_S1_A_INCOME = [
    'nettoomsattning' => 'net_sales',
    'ravaror_och_fornodenheter' => 'raw_materials',
    'ovriga_externa_kostnader' => 'other_external',
    'personalkostnader' => 'personnel',
    'avskrivningar_materiella_anlaggningstillgangar' => 'depreciation',
    'rorelseresultat' => 'operating_result',
    'rantekostnader_och_liknande_resultatposter' => 'interest_expense',
    'resultat_efter_finansiella_poster' => 'after_financial',
    'bokslutsdispositioner_forandring_periodiseringsfonder' => 'appropriations',
    'resultat_fore_skatt' => 'before_tax',
    'skatt_pa_arets_resultat' => 'tax',
    'arets_resultat' => 'year_result',
];

/** expected.json balance sheet key => [kind, key]: a line, a section sum, a group sum or a total */
const F_S1_A_BALANCE = [
    'byggnader_och_mark' => ['line', 'buildings'],
    'maskiner_och_andra_tekniska_anlaggningar' => ['line', 'machinery'],
    'summa_materiella_anlaggningstillgangar' => ['section', 'tangible'],
    'summa_anlaggningstillgangar' => ['group', 'fixed'],
    'ravaror_och_fornodenheter' => ['line', 'raw_materials_stock'],
    'kundfordringar' => ['line', 'trade_receivables'],
    'ovriga_fordringar' => ['line', 'other_receivables'],
    'aktuella_skattefordringar' => ['line', 'current_tax_assets'],
    'kassa_och_bank' => ['line', 'cash'],
    'summa_omsattningstillgangar' => ['group', 'current'],
    'summa_tillgangar' => ['total', 'assets'],
    'aktiekapital' => ['line', 'share_capital'],
    'summa_bundet_eget_kapital' => ['section', 'restricted'],
    'balanserad_vinst_eller_forlust' => ['line', 'retained'],
    'arets_resultat' => ['line', 'year_result'],
    'summa_fritt_eget_kapital' => ['section', 'free'],
    'summa_eget_kapital' => ['total', 'equity'],
    'periodiseringsfonder' => ['line', 'tax_allocation_reserves'],
    'summa_obeskattade_reserver' => ['section', 'untaxed'],
    // F-S1-a has nothing on 225x, so the C.2 line equals its deferred tax liability.
    'uppskjuten_skatteskuld' => ['line', 'tax_provisions'],
    'summa_avsattningar' => ['section', 'provisions'],
    'langfristiga_skulder_till_kreditinstitut' => ['line', 'long_credit_institutions'],
    'summa_langfristiga_skulder' => ['section', 'long_term'],
    'kortfristiga_skulder_till_kreditinstitut' => ['line', 'credit_institutions'],
    'leverantorsskulder' => ['line', 'trade_payables'],
    'skatteskulder' => ['line', 'tax_liabilities'],
    'ovriga_skulder' => ['line', 'other_liabilities'],
    'upplupna_kostnader_och_forutbetalda_intakter' => ['line', 'accrued'],
    'summa_kortfristiga_skulder' => ['section', 'short_term'],
    'summa_eget_kapital_och_skulder' => ['total', 'equity_liabilities'],
];

/**
 * The amount of a balance sheet entry for a column ('amount' or 'previous'); a line that
 * is not printed (zero in both years) counts as 0.
 *
 * @param  array<string, mixed>  $sheet
 * @param  array{0: string, 1: string}  $entry
 */
function k3SheetValue(array $sheet, array $entry, string $field): int
{
    [$kind, $key] = $entry;

    return match ($kind) {
        'total' => (int) $sheet[$field === 'amount' ? $key : "{$key}_previous"],
        'group' => K3Schedule::groupTotals($sheet)[$key][$field],
        'section' => (int) (collect($sheet['sections'])->firstWhere('key', $key)[$field] ?? 0),
        default => (int) (collect($sheet['sections'])->flatMap(fn (array $section): array => $section['lines'])->firstWhere('key', $key)[$field] ?? 0),
    };
}

/**
 * PrintedFigures::apply() on the K3 statements of a year, with the parts of the
 * document data it reads.
 *
 * @return array<string, mixed>
 */
function k3Printed(AnnualAccounts $accounts): array
{
    return PrintedFigures::apply([
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
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    $this->fixture = S1Fixture::load('f-s1-a');
    CurrentCompany::set($this->fixture->company);
    $this->actingAs($this->fixture->owner);
});

it('S1-R8.2 gives F-S1-a\'s K3 income statement for 2025 and 2026 to the öre, and as printed', function () {
    $expected = $this->fixture->expected['income_statement'];
    $accounts = AnnualAccounts::for($this->fixture->years[2026], K3Schedule::class);
    $exact = collect($accounts->incomeStatement())->keyBy('key');
    $printed = collect(k3Printed($accounts)['income'])->keyBy('key');

    foreach (F_S1_A_INCOME as $fixtureKey => $key) {
        expect($exact[$key]['amount'])->toBe($expected['2026'][$fixtureKey], "2026 {$fixtureKey}")
            ->and($exact[$key]['previous'])->toBe($expected['2025'][$fixtureKey], "2025 {$fixtureKey}");
    }

    expect($accounts->movementOf(8910, 8919))->toBe(-$expected['2026']['_skatt_aktuell_8910'])
        ->and($accounts->movementOf(8940, 8949))->toBe(-$expected['2026']['_skatt_uppskjuten_8940']);

    // Printed: every line in whole kronor, every subtotal the sum of the printed lines above it.
    foreach (['amount', 'previous'] as $field) {
        $values = [];

        foreach ($printed as $key => $line) {
            $subtotal = collect(K3Schedule::INCOME_SUBTOTALS)->firstWhere('key', $key);
            $want = $subtotal === null
                ? PrintedFigures::round((int) $exact[$key][$field])
                : array_sum(array_map(fn (string $part): int => $values[$part] ?? 0, $subtotal['lines']));
            expect($line[$field])->toBe($want, "{$field} {$key}")
                ->and($line[$field] % 100)->toBe(0);
            $values[$key] = $line[$field];
        }
    }

    // 520 300,38 kr of tax prints as 520 300 kr; the result is the sum of the rounded lines.
    expect($printed['tax']['previous'])->toBe(-52_030_000)
        ->and($printed['year_result']['amount'])->toBe(348_700_200)
        ->and($printed['year_result']['previous'])->toBe(195_203_000);
});

it('S1-R4.6 gives F-S1-a\'s K3 balance sheet at all three dates to the öre, and as printed it balances', function () {
    $expected = $this->fixture->expected['balance_sheet'];
    $first = AnnualAccounts::for($this->fixture->years[2025], K3Schedule::class);
    $second = AnnualAccounts::for($this->fixture->years[2026], K3Schedule::class);
    $columns = [
        '2024-12-31' => [$first->balanceSheet(), 'previous'],
        '2025-12-31' => [$second->balanceSheet(), 'previous'],
        '2026-12-31' => [$second->balanceSheet(), 'amount'],
    ];

    foreach ($columns as $date => [$sheet, $field]) {
        $want = $expected[$date]['tillgangar'] + $expected[$date]['eget_kapital_och_skulder'];

        foreach (F_S1_A_BALANCE as $fixtureKey => $entry) {
            expect(k3SheetValue($sheet, $entry, $field))->toBe($want[$fixtureKey], "{$date} {$fixtureKey}");
        }
    }

    // The deferred tax liability is on the C.2 line Avsättningar för skatter (K3 29.19, 29.31).
    $provisions = collect($second->balanceSheet()['sections'])->firstWhere('key', 'provisions');
    expect(array_column($provisions['lines'], 'key'))->toBe(['tax_provisions']);

    foreach ([$first, $second] as $accounts) {
        $sheet = k3Printed($accounts)['balance'];

        foreach (['amount', 'previous'] as $field) {
            $suffix = $field === 'amount' ? '' : '_previous';
            $lines = collect($sheet['sections'])->flatMap(fn (array $section): array => $section['lines']);

            expect($lines->every(fn (array $line): bool => $line[$field] % 100 === 0))->toBeTrue()
                ->and($sheet["assets{$suffix}"])->toBe($sheet["equity_liabilities{$suffix}"]);

            foreach ($sheet['sections'] as $section) {
                expect($section[$field])->toBe(array_sum(array_column($section['lines'], $field)));
            }

            $assets = collect($sheet['sections'])->whereIn('key', K3Schedule::ASSET_SECTIONS)->sum($field);
            expect($sheet["assets{$suffix}"])->toBe((int) $assets);
        }
    }

    // As printed at 2026-12-31: the rounded lines, and the totals are their sums.
    $printed = k3Printed($second)['balance'];
    $want = $expected['2026-12-31'];

    foreach (F_S1_A_BALANCE as $fixtureKey => $entry) {
        if ($entry[0] === 'line') {
            $all = $want['tillgangar'] + $want['eget_kapital_och_skulder'];
            expect(k3SheetValue($printed, $entry, 'amount'))->toBe(PrintedFigures::round($all[$fixtureKey]), "printed {$fixtureKey}");
        }
    }

    expect($printed['assets'])->toBe(4_814_644_500)
        ->and(K3Schedule::groupTotals($printed)['fixed']['amount'] + K3Schedule::groupTotals($printed)['current']['amount'])->toBe($printed['assets']);
});

it('S1-R8.3 rounds F-S1-a\'s equity specification without adding a row, and every column adds up', function () {
    // The K3 rows themselves (K3 6.3 a–e) are S1-P7; here only the rounding is K3's.
    $accounts = AnnualAccounts::for($this->fixture->years[2026], K3Schedule::class);
    $rows = collect(k3Printed($accounts)['equityChanges'])->keyBy('key');

    expect($rows->keys()->all())->toBe(array_column($accounts->equityChanges(), 'key'));

    foreach ($rows['opening']['amounts'] as $column => $opening) {
        $changes = $rows->except(['opening', 'closing'])->sum(fn (array $row): int => $row['amounts'][$column]);
        expect($opening + $changes)->toBe($rows['closing']['amounts'][$column], $column);
    }
});

it('reads the ledger with as many queries under K3 as under K2: one for the previous year, one for the chart, two per year, one for goods for resale', function () {
    $count = function (string $schedule): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $accounts = AnnualAccounts::for($this->fixture->years[2026], $schedule);
        $accounts->incomeStatement();
        $accounts->balanceSheet();
        $accounts->fixedAssetNotes();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($count(K3Schedule::class))->toBe($count(K2Schedule::class))
        ->and($count(K2Schedule::class))->toBe(7);
});
