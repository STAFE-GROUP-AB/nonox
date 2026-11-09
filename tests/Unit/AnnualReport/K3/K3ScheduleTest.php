<?php

declare(strict_types=1);

use App\Domain\AnnualReport\K2Schedule;
use App\Domain\AnnualReport\K3Schedule;
use App\Domain\AnnualReport\PrintedFigures;

/*
 * The K3 layout (ADR-059 A.4, S1-P6): ÅRL bilaga 1 and 2 with K3's own posts,
 * every BAS account on exactly one line, every line with its source.
 */

/**
 * @param  list<array{ranges: list<array{0: int, 1: int}>}>  $lines
 * @return array<int, int> account number => how many lines take it
 */
function k3Coverage(array $lines, int $from, int $to): array
{
    $count = array_fill_keys(range($from, $to), 0);

    foreach ($lines as $line) {
        foreach ($line['ranges'] as [$start, $end]) {
            for ($number = max($start, $from); $number <= min($end, $to); $number++) {
                $count[$number]++;
            }
        }
    }

    return $count;
}

it('S1-R4.6 puts BAS 1370 among the financial fixed assets and 2240 among the provisions, on the C.2 line with its specification', function () {
    $lines = collect(K3Schedule::BALANCE)->keyBy('key');

    expect($lines['deferred_tax_asset']['section'])->toBe('financial')
        ->and($lines['deferred_tax_asset']['ranges'])->toBe([[1370, 1379]])
        ->and($lines['tax_provisions']['section'])->toBe('provisions')
        ->and($lines['tax_provisions']['ranges'])->toBe([[2240, 2259]])
        ->and(K3Schedule::DEFERRED_TAX_SPECIFICATION['ranges'])->toBe([[2240, 2249]])
        ->and($lines['current_tax_assets']['ranges'])->toBe([[1640, 1649]]);
});

it('S1-R4.6 keeps the untaxed reserves gross under their own heading (K3 29.37)', function () {
    $untaxed = collect(K3Schedule::BALANCE)->where('section', 'untaxed');

    expect(collect(k3Coverage($untaxed->values()->all(), 2100, 2199))->every(fn (int $count): bool => $count === 1))->toBeTrue()
        ->and(K3Schedule::SECTIONS['untaxed'])->toBe('Obeskattade reserver');
});

it('S1-R10 shows the fund for development expenses as bound equity', function () {
    $fund = collect(K3Schedule::BALANCE)->firstWhere('key', 'development_fund');

    expect($fund['section'])->toBe('restricted')
        ->and($fund['label'])->toBe('Fond för utvecklingsutgifter')
        ->and($fund['ranges'])->toBe([[2089, 2089]])
        ->and(K3Schedule::GROUPS['equity']['sections'])->toBe(['restricted', 'free']);
});

it('S1-R8.2 lists the income statement in the order of ÅRL bilaga 2', function () {
    expect(array_column(K3Schedule::INCOME, 'key'))->toBe([
        'net_sales', 'stock_change', 'capitalised_work', 'other_operating_income',
        'raw_materials', 'goods_for_resale', 'other_external', 'personnel', 'depreciation',
        'current_asset_write_downs', 'other_operating_expenses',
        'group_shares', 'associated_shares', 'ownership_interests', 'other_financial_assets',
        'interest_income', 'financial_write_downs', 'interest_expense',
        'appropriations', 'tax', 'other_taxes',
    ]);
});

it('S1-R8.2 lists the balance sheet sections in the order of ÅRL bilaga 1, with long- and short-term liabilities apart', function () {
    expect(array_keys(K3Schedule::SECTIONS))->toBe([
        'subscribed', 'intangible', 'tangible', 'financial', 'inventory', 'receivables', 'short_investments', 'cash',
        'restricted', 'free', 'untaxed', 'provisions', 'long_term', 'short_term',
    ]);

    $sections = array_values(array_unique(array_column(K3Schedule::BALANCE, 'section')));
    expect($sections)->toBe(array_keys(K3Schedule::SECTIONS));
});

it('S1-R8.2 orders the parts as K3 3.10: management report, income statement, balance sheet, equity statement, cash flow, notes', function () {
    expect(K3Schedule::PARTS)->toBe(['management_report', 'income_statement', 'balance_sheet', 'equity_statement', 'cash_flow_statement', 'notes']);
});

it('S1-R8.2 puts every account on exactly one line, the same accounts as K2', function () {
    $k3Balance = k3Coverage(K3Schedule::BALANCE, 1000, 2999);
    $k2Balance = k3Coverage(K2Schedule::BALANCE, 1000, 2999);
    $k3Income = k3Coverage(K3Schedule::INCOME, 3000, 8999);
    $k2Income = k3Coverage(K2Schedule::INCOME, 3000, 8999);

    expect(array_filter($k3Balance, fn (int $count): bool => $count > 1))->toBe([])
        ->and(array_filter($k3Income, fn (int $count): bool => $count > 1))->toBe([])
        ->and(array_keys(array_filter($k3Balance)))->toBe(array_keys(array_filter($k2Balance)))
        ->and(array_keys(array_filter($k3Income)))->toBe(array_keys(array_filter($k2Income)));
});

it('S1-R8.2 names a source for every line and keeps unique keys', function () {
    foreach ([...K3Schedule::INCOME, ...K3Schedule::BALANCE] as $line) {
        expect($line['source'])->toMatch('/ÅRL|K3/', $line['key']);
    }

    expect(array_unique(array_column(K3Schedule::BALANCE, 'key')))->toHaveCount(count(K3Schedule::BALANCE))
        ->and(array_unique(array_column(K3Schedule::INCOME, 'key')))->toHaveCount(count(K3Schedule::INCOME));
});

it('S1-R8.2 makes every subtotal add lines that exist above it', function () {
    $seen = [];

    foreach (K3Schedule::INCOME as $line) {
        $seen[] = $line['key'];
        $subtotal = K3Schedule::INCOME_SUBTOTALS[$line['key']] ?? null;

        if ($subtotal !== null) {
            expect(array_diff($subtotal['lines'], $seen))->toBe([], $subtotal['key']);
            $seen[] = $subtotal['key'];
        }
    }

    expect(end($seen))->toBe('year_result');
});

it('S1-R8.3 never adds an "Övriga förändringar" row to a K3 equity specification', function () {
    $rows = [
        ['key' => 'opening', 'label' => 'Belopp vid årets ingång', 'amounts' => ['retained' => 1_000_040, 'year_result' => 500_040], 'total' => 1_500_080],
        ['key' => 'dividend', 'label' => 'Utdelning', 'amounts' => ['retained' => 0, 'year_result' => -100_060], 'total' => -100_060],
        ['key' => 'carried_forward', 'label' => 'Balanseras i ny räkning', 'amounts' => ['retained' => 399_980, 'year_result' => -399_980], 'total' => 0],
        ['key' => 'year_result', 'label' => 'Årets resultat', 'amounts' => ['retained' => 0, 'year_result' => 700_000], 'total' => 700_000],
        ['key' => 'closing', 'label' => 'Belopp vid årets utgång', 'amounts' => ['retained' => 1_400_020, 'year_result' => 700_000], 'total' => 2_100_020],
    ];
    $sheet = ['sections' => [['key' => 'free', 'lines' => [
        ['key' => 'retained', 'amount' => 1_400_000, 'previous' => 1_000_000],
        ['key' => 'year_result', 'amount' => 700_000, 'previous' => 500_000],
    ]]]];

    $method = new ReflectionMethod(PrintedFigures::class, 'equity');
    $k3 = collect($method->invoke(null, $rows, $sheet, 700_000, K3Schedule::class))->keyBy('key');

    expect($k3->keys()->all())->toBe(['opening', 'dividend', 'carried_forward', 'year_result', 'closing']);

    foreach (['retained', 'year_result'] as $column) {
        $changes = $k3->except(['opening', 'closing'])->sum(fn (array $row): int => $row['amounts'][$column]);
        expect($k3['opening']['amounts'][$column] + $changes)->toBe($k3['closing']['amounts'][$column]);
    }

    // Only the dividend has an amount in a column where nothing else does: it takes the leftover.
    $onlyDividend = [
        ['key' => 'opening', 'label' => 'Belopp vid årets ingång', 'amounts' => ['retained' => 1_000_040], 'total' => 1_000_040],
        ['key' => 'dividend', 'label' => 'Utdelning', 'amounts' => ['retained' => -100_060], 'total' => -100_060],
        ['key' => 'closing', 'label' => 'Belopp vid årets utgång', 'amounts' => ['retained' => 899_980], 'total' => 899_980],
    ];
    $sheet = ['sections' => [['key' => 'free', 'lines' => [['key' => 'retained', 'amount' => 900_000, 'previous' => 1_000_000]]]]];
    $k3 = collect($method->invoke(null, $onlyDividend, $sheet, 0, K3Schedule::class))->keyBy('key');

    expect($k3->keys()->all())->toBe(['opening', 'dividend', 'closing'])
        ->and($k3['dividend']['amounts']['retained'])->toBe(-100_000);

    // K2 keeps its "Övriga förändringar" row for a column with no change row.
    $noChange = [
        ['key' => 'opening', 'label' => 'Belopp vid årets ingång', 'amounts' => ['share_capital' => 2_500_000], 'total' => 2_500_000],
        ['key' => 'year_result', 'label' => 'Årets resultat', 'amounts' => ['share_capital' => 0], 'total' => 0],
        ['key' => 'closing', 'label' => 'Belopp vid årets utgång', 'amounts' => ['share_capital' => 2_500_000], 'total' => 2_500_000],
    ];
    $sheet = ['sections' => [['key' => 'restricted', 'lines' => [['key' => 'share_capital', 'amount' => 2_600_000, 'previous' => 2_500_000]]]]];
    $k2 = collect($method->invoke(null, $noChange, $sheet, 0, K2Schedule::class))->keyBy('key');

    expect($k2->keys()->all())->toBe(['opening', 'year_result', 'other', 'closing'])
        ->and(fn () => $method->invoke(null, $noChange, $sheet, 0, K3Schedule::class))->toThrow(DomainException::class);
});

it('S1-R4.6 shows ÅRL bilaga 1 C.2 as one line, Avsättningar för skatter, over BAS 2240–2259 (S1-P12 re-check)', function () {
    $provisions = collect(K3Schedule::BALANCE)->where('section', 'provisions')->keyBy('key');

    expect($provisions->keys()->all())->toBe(['pension_provisions', 'tax_provisions', 'other_provisions'])
        ->and($provisions['tax_provisions']['ranges'])->toBe([[2240, 2259]])
        ->and($provisions['tax_provisions']['label'])->toBe('Avsättningar för skatter')
        ->and($provisions['other_provisions']['ranges'])->toBe([[2200, 2209], [2220, 2229], [2260, 2299]]);
});

it('S1-R10 puts BAS 1081 on Balanserade utgifter för utvecklingsarbeten (rbas2026)', function () {
    $lines = collect(K3Schedule::BALANCE)->keyBy('key');

    expect($lines['development_expenditure']['ranges'])->toBe([[1010, 1019], [1081, 1081]])
        ->and($lines['intangible_advances']['ranges'])->toBe([[1080, 1080], [1082, 1089]]);
});

it('S1-R8.2 prints a K3 sheet whose fixed-asset lines carry worst-case rounding from their six note figures', function () {
    // Each note figure rounds 49 öre the unfavourable way, so each book value is 294 öre below the exact one.
    $note = fn (string $key): array => [
        'key' => $key, 'label' => $key,
        'acquisition' => ['opening' => 10_049, 'additions' => 49, 'disposals' => 51, 'closing' => 10_047],
        'depreciation' => ['opening' => 51, 'disposals' => 49, 'charge' => 51, 'closing' => 53],
        'book_value' => 9_994,
    ];
    $notes = array_map($note, K3Schedule::FIXED_ASSET_LINES);
    $printedNotes = (new ReflectionMethod(PrintedFigures::class, 'fixedAssets'))->invoke(null, $notes);
    $exact = 9_994 * count($notes);
    $sections = [];

    foreach (K3Schedule::FIXED_ASSET_LINES as $key) {
        $section = collect(K3Schedule::BALANCE)->firstWhere('key', $key)['section'];
        $sections[$section]['lines'][] = ['key' => $key, 'label' => $key, 'amount' => 9_994, 'previous' => 0];
    }

    $sheet = ['sections' => [
        ...array_map(fn (string $key, array $section): array => ['key' => $key, 'label' => $key, 'lines' => $section['lines'], 'amount' => 0, 'previous' => 0], array_keys($sections), $sections),
        ['key' => 'free', 'label' => 'Fritt eget kapital', 'lines' => [['key' => 'retained', 'label' => 'Balanserad vinst eller förlust', 'amount' => $exact, 'previous' => 0]], 'amount' => $exact, 'previous' => 0],
    ], 'assets' => $exact, 'assets_previous' => 0, 'equity_liabilities' => $exact, 'equity_liabilities_previous' => 0];

    $balance = (new ReflectionMethod(PrintedFigures::class, 'balance'))->invoke(null, $sheet, ['amount' => [], 'previous' => []], true, $printedNotes, K3Schedule::class);

    expect(collect($balance['sections'])->firstWhere('key', 'tangible')['lines'][0]['amount'])->toBe(9_700)
        ->and($balance['assets'])->toBe($balance['equity_liabilities']);
});

it('S1-R8.2 heads the receivables "Fordringar" as ÅRL bilaga 1 C.II', function () {
    expect(K3Schedule::SECTIONS['receivables'])->toBe('Fordringar');
});

it('S1-R8.2 says why a label differs from the text of ÅRL bilaga 2, and marks the koncernföretag disclosures', function () {
    $lines = collect(K3Schedule::INCOME)->keyBy('key');

    foreach (['group_shares', 'associated_shares', 'ownership_interests', 'other_financial_assets', 'interest_income', 'interest_expense'] as $key) {
        expect($lines[$key]['source'])->toContain('label differs from bilaga 2 text (3:4 tredje stycket)');
    }

    foreach (['other_financial_assets', 'interest_income', 'interest_expense'] as $key) {
        expect($lines[$key]['source'])->toContain('TODO(legal-verify)');
    }
});
