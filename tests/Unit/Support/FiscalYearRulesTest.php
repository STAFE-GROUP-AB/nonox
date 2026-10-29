<?php

declare(strict_types=1);

use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\FiscalYearRules;
use Carbon\CarbonImmutable;

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

it('finds the fiscal year that contains a date', function (string $date, int $startMonth, string $start, string $end) {
    [$from, $to] = FiscalYearRules::yearContaining(day($date), $startMonth);

    expect($from->toDateString())->toBe($start)
        ->and($to->toDateString())->toBe($end);
})->with([
    'calendar year' => ['2026-10-29', 1, '2026-01-01', '2026-12-31'],
    'broken year, after start' => ['2026-10-29', 7, '2026-07-01', '2027-06-30'],
    'broken year, before start' => ['2026-03-15', 7, '2025-07-01', '2026-06-30'],
    'first day of the year' => ['2026-05-01', 5, '2026-05-01', '2027-04-30'],
    'last day of the year' => ['2026-04-30', 5, '2025-05-01', '2026-04-30'],
]);

it('accepts valid fiscal years', function (string $start, string $end, bool $mayDeviate) {
    expect(FiscalYearRules::problem(day($start), day($end), $mayDeviate))->toBeNull();
})->with([
    'twelve months' => ['2026-01-01', '2026-12-31', false],
    'broken twelve months' => ['2026-07-01', '2027-06-30', false],
    'short first year' => ['2026-05-01', '2026-12-31', true],
    'long first year, 18 months' => ['2025-07-01', '2026-12-31', true],
]);

it('explains what is wrong with an invalid fiscal year', function (string $start, string $end, bool $mayDeviate, string $message) {
    expect(FiscalYearRules::problem(day($start), day($end), $mayDeviate))->toContain($message);
})->with([
    'not the first of a month' => ['2026-01-15', '2026-12-31', true, 'första dagen i en månad'],
    'not the last of a month' => ['2026-01-01', '2026-12-30', true, 'sista dagen i en månad'],
    'end before start' => ['2026-12-01', '2026-01-31', true, 'Slutdatumet måste komma efter'],
    'normal year not twelve months' => ['2026-01-01', '2026-06-30', false, 'tolv månader'],
    'longer than eighteen months' => ['2025-01-01', '2026-12-31', true, 'mellan 6 och 18 månader'],
    'shorter than six months' => ['2026-10-01', '2026-12-31', true, 'mellan 6 och 18 månader'],
]);

it('derives the account type from the first digit', function (int $number, AccountType $type) {
    expect(AccountType::forNumber($number))->toBe($type);
})->with([
    [1930, AccountType::Asset],
    [2081, AccountType::LiabilityEquity],
    [3001, AccountType::Revenue],
    [4010, AccountType::Expense],
    [6570, AccountType::Expense],
    [8999, AccountType::Expense],
]);
