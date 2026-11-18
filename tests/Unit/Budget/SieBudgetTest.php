<?php

declare(strict_types=1);

use App\Domain\Budget\Sie\SieBudget;
use App\Support\Sie\SieReader;

/*
 * SIE #PBUDGET (SIE filformat utgåva 4C, "#PBUDGET Periodbudgetpost", p. 29)
 * and its mapping to budget lines (ADR-081 E4). Pure: no database.
 */

/** @param list<string> $lines */
function e4SieBudgetOf(array $lines, string $type = '4'): SieBudget
{
    $path = tempnam(sys_get_temp_dir(), 'e4sie');
    file_put_contents($path, implode("\r\n", ['#FLAGGA 0', "#SIETYP {$type}", '#RAR 0 20260101 20261231', ...$lines])."\r\n");

    $budget = new SieBudget;

    foreach ((new SieReader($path))->records() as $record) {
        $budget->read($record);
    }

    unlink($path);

    return $budget;
}

it('reads the specification\'s own examples: credit negative, period ÅÅÅÅMM, one object', function () {
    $budget = e4SieBudgetOf([
        '#PBUDGET 0 202601 3011 {} -1243.50 -415',
        '#PBUDGET 0 202601 5010 {} 3411.80',
        '#PBUDGET 0 202601 5010 {1 "0123"} 3411.80',
    ]);

    expect($budget->problems())->toBe([])
        ->and($budget->lines())->toBe([
            ['account' => 3011, 'period' => '2026-01', 'cost_center' => null, 'project' => null, 'amount' => -124_350],
            ['account' => 5010, 'period' => '2026-01', 'cost_center' => '0123', 'project' => null, 'amount' => 341_180],
        ])
        ->and($budget->notes())->toContain('Kvantiteter i budgeten läses inte in, bara belopp.');
});

it('keeps the whole amount, each cost centre and each project exact when both are given', function () {
    $budget = e4SieBudgetOf([
        '#PBUDGET 0 202603 5010 {} 1000.00',
        '#PBUDGET 0 202603 5010 {1 "10"} 600.00',
        '#PBUDGET 0 202603 5010 {1 "20"} 400.00',
        '#PBUDGET 0 202603 5010 {6 "P1"} 250.00',
    ]);

    $lines = $budget->lines();
    $sum = fn (?string $costCenter, ?string $project) => array_sum(array_column(array_filter($lines, fn (array $line) => ($costCenter === null || $line['cost_center'] === $costCenter) && ($project === null || $line['project'] === $project)), 'amount'));

    expect($sum(null, null))->toBe(100_000)
        ->and($sum('10', null))->toBe(60_000)
        ->and($sum('20', null))->toBe(40_000)
        ->and($sum(null, 'P1'))->toBe(25_000)
        ->and($budget->notes())->toHaveCount(2)
        ->and($budget->notes()[1])->toContain('1 konton och månader får en rad utan kostnadsställe och projekt')->toContain('konto 5010 2026-03 med -250,00');

    // Written back, the three figures come out as they went in.
    expect(SieBudget::recordsFor($lines))->toBe([
        ['account' => 5010, 'period' => '2026-03', 'dimension' => null, 'code' => null, 'amount' => 100_000],
        ['account' => 5010, 'period' => '2026-03', 'dimension' => 1, 'code' => '10', 'amount' => 60_000],
        ['account' => 5010, 'period' => '2026-03', 'dimension' => 1, 'code' => '20', 'amount' => 40_000],
        ['account' => 5010, 'period' => '2026-03', 'dimension' => 6, 'code' => 'P1', 'amount' => 25_000],
    ]);
});

it('takes the objects as the whole amount when the file gives only objects', function () {
    $lines = e4SieBudgetOf([
        '#PBUDGET 0 202602 6110 {1 "10"} 100.00',
        '#PBUDGET 0 202602 6110 {1 "20"} 50.00',
    ])->lines();

    expect(array_sum(array_column($lines, 'amount')))->toBe(15_000)
        ->and(array_column($lines, 'cost_center'))->toBe(['10', '20']);
});

it('ignores objects in a type 2 file, as the format says a type 2 reader must', function () {
    $budget = e4SieBudgetOf([
        '#PBUDGET 0 202601 3001 {} -500.00',
        '#PBUDGET 0 202601 3001 {1 "10"} -500.00',
    ], type: '2');

    expect($budget->lines())->toBe([['account' => 3001, 'period' => '2026-01', 'cost_center' => null, 'project' => null, 'amount' => -50_000]])
        ->and(implode(' ', $budget->notes()))->toContain('SIE-typ 2');
});

it('leaves out other years, balance accounts, other dimensions and object lists', function () {
    $budget = e4SieBudgetOf([
        '#PBUDGET -1 202501 3001 {} -100.00',
        '#PBUDGET 0 202601 1930 {} 100.00',
        '#PBUDGET 0 202601 8999 {} 100.00',
        '#PBUDGET 0 202601 5010 {} 200.00',
        '#PBUDGET 0 202601 5010 {7 "23"} 200.00',
        '#PBUDGET 0 202601 5010 {1 "10" 6 "P1"} 200.00',
    ]);

    expect($budget->problems())->toBe([])
        ->and($budget->otherYears())->toBe(1)
        ->and($budget->lines())->toBe([['account' => 5010, 'period' => '2026-01', 'cost_center' => null, 'project' => null, 'amount' => 20_000]])
        ->and(implode(' ', $budget->notes()))->toContain('andra räkenskapsår')->toContain('1930, 8999')->toContain('dimension 7')->toContain('mer än ett objekt');
});

it('refuses a budget it cannot read reliably, and says where', function (string $line, string $reason) {
    $budget = e4SieBudgetOf(['#PBUDGET 0 202601 5010 {} 100.00', $line]);

    expect(implode(' ', $budget->problems()))->toContain($reason);
})->with([
    'a month outside the year' => ['#PBUDGET 0 202701 5010 {} 1.00', 'utanför räkenskapsåret'],
    'an unreadable month' => ['#PBUDGET 0 2026-1 5010 {} 1.00', 'går inte att läsa'],
    'month 13' => ['#PBUDGET 0 202613 5010 {} 1.00', 'ÅÅÅÅMM'],
    'an account of three digits' => ['#PBUDGET 0 202602 501 {} 1.00', 'fyra siffror'],
    'a decimal comma' => ['#PBUDGET 0 202602 5010 {} 1,00', 'budgetbeloppet'],
    'the same cell twice' => ['#PBUDGET 0 202601 5010 {} 5.00', 'finns redan på rad'],
]);

it('skips a record with two objects of the same dimension instead of merging them', function () {
    $budget = e4SieBudgetOf([
        '#PBUDGET 0 202601 5010 {} 300.00',
        '#PBUDGET 0 202601 5010 {1 "10" 1 "20"} 300.00',
    ]);

    expect($budget->problems())->toBe([])
        ->and($budget->lines())->toBe([['account' => 5010, 'period' => '2026-01', 'cost_center' => null, 'project' => null, 'amount' => 30_000]])
        ->and(implode(' ', $budget->notes()))->toContain('1 budgetposter anger mer än ett objekt');
});

it('refuses an object code longer than the registers here hold', function () {
    $budget = e4SieBudgetOf(['#PBUDGET 0 202601 5010 {1 "ABCDEFGHIJKLMNOPQRSTU"} 1.00']);

    expect(implode(' ', $budget->problems()))->toContain('objektkoden "ABCDEFGHIJKLMNOPQRSTU" är längre än 20 tecken');
});

it('takes the larger object sum as the whole amount when {} is missing and both dimensions are given, and says so', function () {
    $budget = e4SieBudgetOf([
        '#PBUDGET 0 202604 6110 {1 "10"} 100.00',
        '#PBUDGET 0 202604 6110 {6 "P1"} -400.00',
    ]);

    expect(array_sum(array_column($budget->lines(), 'amount')))->toBe(-40_000)
        ->and(implode(' ', $budget->notes()))->toContain('saknar filen kontots hela belopp')->toContain('konto 6110 2026-04 med -100,00');
});

it('writes the whole amount also when it is zero, so a reader never has to guess it', function () {
    expect(SieBudget::recordsFor([
        ['account' => 6110, 'period' => '2026-05', 'cost_center' => null, 'project' => null, 'amount' => -100],
        ['account' => 6110, 'period' => '2026-05', 'cost_center' => '10', 'project' => null, 'amount' => 100],
        ['account' => 6110, 'period' => '2026-06', 'cost_center' => null, 'project' => null, 'amount' => 0],
    ]))->toBe([
        ['account' => 6110, 'period' => '2026-05', 'dimension' => null, 'code' => null, 'amount' => 0],
        ['account' => 6110, 'period' => '2026-05', 'dimension' => 1, 'code' => '10', 'amount' => 100],
    ]);
});
