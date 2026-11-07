<?php

declare(strict_types=1);

use App\Domain\AnnualReport\K3EquityStatement;
use App\Domain\AnnualReport\K3EquityStatementRefused;
use App\Domain\AnnualReport\K3Schedule;
use App\Domain\AnnualReport\PrintedFigures;

/*
 * S1-P7 (ADR-059 A.4): the rows of K3 6.3 and how PrintedFigures rounds them.
 */

/**
 * @param  array<string, array<string, int>>  $rows  key => amounts
 * @return list<array{key: string, label: string, amounts: array<string, int>, total: int}>
 */
function k3EquityRows(array $rows): array
{
    return array_map(fn (string $key, array $amounts): array => ['key' => $key, 'label' => $key, 'amounts' => $amounts, 'total' => array_sum($amounts)], array_keys($rows), $rows);
}

it('S1-R8.3 gives every row of the statement a point of K3 6.3, and none is a residual', function () {
    expect(array_keys(K3EquityStatement::LABELS))->toBe(array_keys(K3EquityStatement::POINTS))
        ->and(array_values(array_unique(array_filter(K3EquityStatement::POINTS))))->toEqualCanonicalizing(['a', 'b', 'd', 'e'])
        ->and(K3EquityStatement::POINTS['year_result'])->toBe('a')
        ->and(K3EquityStatement::POINTS['retroactive'])->toBe('b')
        ->and(K3EquityStatement::POINTS['dividend'])->toBe('d')
        ->and(K3EquityStatement::POINTS['prior_result_transfer'])->toBe('e')
        ->and(K3EquityStatement::LABELS)->not->toContain('Övriga förändringar');
});

it('S1-R8.3 puts a rounding leftover on an existing row of the K3 statement, never on a new one', function () {
    // Exact: retained 1 000,40 + 400,40 = 1 400,80 kr, printed 1 000 + 400 = 1 400 against a printed closing of 1 401.
    $rows = k3EquityRows([
        'opening' => ['retained' => 100_040, 'year_result' => 40_040],
        'prior_result_transfer' => ['retained' => 40_040, 'year_result' => -40_040],
        'year_result' => ['retained' => 0, 'year_result' => 70_000],
        'closing' => ['retained' => 140_080, 'year_result' => 70_000],
    ]);
    $sheet = ['sections' => [['key' => 'free', 'lines' => [
        ['key' => 'retained', 'amount' => 140_100, 'previous' => 100_000],
        ['key' => 'year_result', 'amount' => 70_000, 'previous' => 40_000],
    ]]]];

    $printed = collect((new ReflectionMethod(PrintedFigures::class, 'equity'))->invoke(null, $rows, $sheet, 70_000, K3Schedule::class))->keyBy('key');

    expect($printed->keys()->all())->toBe(['opening', 'prior_result_transfer', 'year_result', 'closing'])
        ->and($printed['prior_result_transfer']['amounts'])->toBe(['retained' => 40_100, 'year_result' => -40_000]);
});

it('S1-R8.3 refuses, with a reason, a K3 rounding leftover that no row can take', function () {
    $rows = k3EquityRows([
        'opening' => ['share_capital' => 2_500_000],
        'year_result' => ['share_capital' => 0],
        'closing' => ['share_capital' => 2_500_000],
    ]);
    $sheet = ['sections' => [['key' => 'restricted', 'lines' => [['key' => 'share_capital', 'amount' => 2_600_000, 'previous' => 2_500_000]]]]];

    try {
        (new ReflectionMethod(PrintedFigures::class, 'equity'))->invoke(null, $rows, $sheet, 0, K3Schedule::class);
        $this->fail('Expected a refusal.');
    } catch (K3EquityStatementRefused $refusal) {
        expect($refusal->reason)->toContain('Aktiekapital')
            ->and($refusal->reason)->toContain('6.3')
            ->and($refusal->getMessage())->toBe($refusal->reason)
            ->and($refusal->accounts)->toBe([2081]);
    }
});
