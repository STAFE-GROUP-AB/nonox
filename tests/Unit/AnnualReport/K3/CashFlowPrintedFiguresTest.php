<?php

declare(strict_types=1);

use App\Domain\AnnualReport\CashFlowAccounts;
use App\Domain\AnnualReport\CashFlowPrintedFigures;
use App\Domain\AnnualReport\CashFlowStatement;
use App\Domain\AnnualReport\CashFlowStatementRefused;

/*
 * S1-P8 (ADR-059 A.4): the account categories of the cash flow statement
 * and its printed figures, without a database.
 */

/**
 * Rows in the shape of CashFlowStatement::rows() from line amounts (öre); the
 * sums are computed exactly, as the statement does.
 *
 * @param  array<string, int>  $lines
 * @return list<array{key: string, label: string, section: string, point: string, subtotal: bool, amount: int, previous: ?int}>
 */
function cashFlowRows(array $lines): array
{
    foreach (CashFlowStatement::SUBTOTALS as $key => $parts) {
        $lines[$key] = array_sum(array_map(fn (string $part): int => $lines[$part] ?? 0, $parts));
    }

    $rows = [];

    foreach (CashFlowStatement::LINES as $key => $line) {
        if (array_key_exists($key, $lines)) {
            $rows[] = ['key' => $key, 'label' => $line['label'], 'section' => $line['section'], 'point' => $line['point'], 'subtotal' => $line['subtotal'], 'amount' => $lines[$key], 'previous' => null];
        }
    }

    return $rows;
}

it('S1-R2.1 places the BAS accounts: 19xx cash, overdrafts 2330 and 2480 loans, tax separately, dividends with equity', function (int $number, ?string $category) {
    expect(CashFlowAccounts::categoryOf($number))->toBe($category);
})->with([
    [1930, CashFlowAccounts::CASH],
    [1910, CashFlowAccounts::CASH],
    [2330, CashFlowAccounts::OVERDRAFT],
    [2480, CashFlowAccounts::OVERDRAFT],
    [2350, CashFlowAccounts::LOANS],
    [2410, CashFlowAccounts::LOANS],
    [2893, CashFlowAccounts::LOANS],
    [1119, CashFlowAccounts::ACCUMULATED],
    [1229, CashFlowAccounts::ACCUMULATED],
    [1018, CashFlowAccounts::ACCUMULATED],
    // Advances and work in progress (K3Schedule, from the rbas2026 mapping: 108x, 118x, 128x) carry no depreciation.
    [1088, CashFlowAccounts::INTANGIBLE],
    [1188, CashFlowAccounts::TANGIBLE],
    [1288, CashFlowAccounts::TANGIBLE],
    [1289, CashFlowAccounts::TANGIBLE],
    [1210, CashFlowAccounts::TANGIBLE],
    [1130, CashFlowAccounts::TANGIBLE],
    [1070, CashFlowAccounts::INTANGIBLE],
    [1370, CashFlowAccounts::DEFERRED_TAX],
    [2240, CashFlowAccounts::DEFERRED_TAX],
    [8940, CashFlowAccounts::DEFERRED_TAX],
    [1380, CashFlowAccounts::FINANCIAL_ASSETS],
    [1410, CashFlowAccounts::INVENTORY],
    [1510, CashFlowAccounts::RECEIVABLES],
    [1630, CashFlowAccounts::RECEIVABLES],
    [1640, CashFlowAccounts::INCOME_TAX],
    [2512, CashFlowAccounts::INCOME_TAX],
    [8910, CashFlowAccounts::INCOME_TAX],
    [2514, CashFlowAccounts::PAYABLES],
    [2440, CashFlowAccounts::PAYABLES],
    [2650, CashFlowAccounts::PAYABLES],
    [2920, CashFlowAccounts::PAYABLES],
    [2898, CashFlowAccounts::EQUITY],
    [2091, CashFlowAccounts::EQUITY],
    [8999, CashFlowAccounts::EQUITY],
    [2125, CashFlowAccounts::UNTAXED_RESERVES],
    [8811, CashFlowAccounts::UNTAXED_RESERVES],
    [2290, CashFlowAccounts::PROVISIONS],
    [7820, CashFlowAccounts::DEPRECIATION],
    [7740, CashFlowAccounts::RESULT],
    [8410, CashFlowAccounts::RESULT],
    [1810, CashFlowAccounts::SHORT_INVESTMENTS],
    // No category: equity of other legal forms, subscribed capital, amounts with group
    // companies that may be trade or loans, internal accounts, and the not-built ranges.
    [2010, null],
    [1690, null],
    [2865, null],
    [1660, null],
    [1680, null],
    [1685, null],
    [3960, null],
    [7960, null],
    [8330, null],
    [8430, null],
    [9100, null],
    [3973, null],
    [7970, null],
    [8220, null],
    [8820, null],
]);

it('S1-R2.1 names why a range is not built yet', function () {
    expect(CashFlowAccounts::notBuilt(3973))->toBe('disposal')
        ->and(CashFlowAccounts::notBuilt(8270))->toBe('financial')
        ->and(CashFlowAccounts::notBuilt(8830))->toBe('group_contribution')
        ->and(CashFlowAccounts::notBuilt(3960))->toBe('currency')
        ->and(CashFlowAccounts::notBuilt(8430))->toBe('currency')
        ->and(CashFlowAccounts::notBuilt(8410))->toBeNull();
});

it('S1-R2.1 prints whole kronor whose sums are the sums of the printed lines', function () {
    $printed = collect(CashFlowPrintedFigures::apply(cashFlowRows([
        'resultat_efter_finansiella_poster' => 100_049,
        'betald_inkomstskatt' => -20_049,
        'forandring_rorelseskulder' => 10_000,
        'forvarv_av_materiella_anlaggningstillgangar' => -50_000,
        'likvida_medel_vid_arets_borjan' => 1_000_000,
        'likvida_medel_vid_arets_slut' => 1_040_000,
    ])))->keyBy('key');

    expect($printed['resultat_efter_finansiella_poster']['amount'])->toBe(100_000)
        ->and($printed['betald_inkomstskatt']['amount'])->toBe(-20_000)
        ->and($printed['kassaflode_fore_forandring_av_rorelsekapital']['amount'])->toBe(80_000)
        ->and($printed['arets_kassaflode']['amount'])->toBe(40_000);
});

it('S1-R2.1 puts a rounding leftover on Förändring av rörelseskulder, never on a row of its own', function () {
    // Exact: 100,50 + 100,50 + 1,00 = 202,00 kr; printed 101 + 101 + 1 = 203, so 1 kr is left over.
    $printed = collect(CashFlowPrintedFigures::apply(cashFlowRows([
        'resultat_efter_finansiella_poster' => 10_050,
        'betald_inkomstskatt' => 10_050,
        'forandring_rorelseskulder' => 100,
        'likvida_medel_vid_arets_borjan' => 0,
        'likvida_medel_vid_arets_slut' => 20_200,
    ])))->keyBy('key');

    expect($printed->keys()->all())->not->toContain('other')
        ->and($printed['forandring_rorelseskulder']['amount'])->toBe(0)
        ->and($printed['arets_kassaflode']['amount'])->toBe(20_200)
        ->and($printed['kassaflode_fran_den_lopande_verksamheten']['amount'])->toBe(20_200);
});

it('S1-R2.1 refuses a leftover with no working capital line to take it', function () {
    expect(fn () => CashFlowPrintedFigures::apply(cashFlowRows([
        'resultat_efter_finansiella_poster' => 10_050,
        'betald_inkomstskatt' => 10_050,
        'likvida_medel_vid_arets_borjan' => 0,
        'likvida_medel_vid_arets_slut' => 20_100,
    ])))->toThrow(CashFlowStatementRefused::class, 'ingen rad');
});

it('S1-R2.1 refuses a difference larger than rounding can explain', function () {
    expect(fn () => CashFlowPrintedFigures::apply(cashFlowRows([
        'resultat_efter_finansiella_poster' => 100_000,
        'forandring_rorelseskulder' => 100_000,
        'likvida_medel_vid_arets_borjan' => 0,
        'likvida_medel_vid_arets_slut' => 500_000,
    ])))->toThrow(CashFlowStatementRefused::class, 'mer än avrundningen');
});
