<?php

declare(strict_types=1);

use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\FiscalYearRules;
use App\Domain\Company\LegalForm;
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

it('accepts valid fiscal years', function (string $start, string $end, bool $mayDeviate, bool $calendarYear = false) {
    expect(FiscalYearRules::problem(day($start), day($end), $mayDeviate, $calendarYear))->toBeNull();
})->with([
    'twelve months' => ['2026-01-01', '2026-12-31', false],
    'broken twelve months' => ['2026-07-01', '2027-06-30', false],
    'short first year' => ['2026-05-01', '2026-12-31', true],
    'one month, no minimum length' => ['2026-12-01', '2026-12-31', true],
    'three months' => ['2026-10-01', '2026-12-31', true],
    'calendar year' => ['2026-01-01', '2026-12-31', false, true],
    'short first calendar year' => ['2026-09-01', '2026-12-31', true, true],
    'long first calendar year' => ['2025-09-01', '2026-12-31', true, true],
    'long first year, 18 months' => ['2025-07-01', '2026-12-31', true],
]);

it('explains what is wrong with an invalid fiscal year', function (string $start, string $end, bool $mayDeviate, string $message, bool $calendarYear = false) {
    expect(FiscalYearRules::problem(day($start), day($end), $mayDeviate, $calendarYear))->toContain($message);
})->with([
    'not the first of a month' => ['2026-01-15', '2026-12-31', true, 'första dagen i en månad'],
    'not the last of a month' => ['2026-01-01', '2026-12-30', true, 'sista dagen i en månad'],
    'end before start' => ['2026-12-01', '2026-01-31', true, 'Slutdatumet måste komma efter'],
    'normal year not twelve months' => ['2026-01-01', '2026-06-30', false, 'tolv månader'],
    'longer than eighteen months' => ['2025-01-01', '2026-12-31', true, 'högst 18 månader'],
    'calendar year required, broken year' => ['2026-07-01', '2027-06-30', true, 'sluta den 31 december', true],
    'calendar year required, short broken year' => ['2026-03-01', '2026-06-30', true, 'sluta den 31 december', true],
]);

it('gives the next year twelve months, or moves a calendar-year company to 31 December', function (string $start, bool $calendarYear, string $end) {
    [, $to] = FiscalYearRules::nextYear(day($start), $calendarYear);

    expect($to->toDateString())->toBe($end);
})->with([
    'broken year' => ['2026-05-01', false, '2027-04-30'],
    'calendar year' => ['2027-01-01', true, '2027-12-31'],
    'broken year moved to the calendar year' => ['2026-05-01', true, '2026-12-31'],
]);

it('knows which legal forms must use the calendar year', function () {
    expect(FiscalYearRules::requiresCalendarYear(LegalForm::Ef))->toBeTrue()
        ->and(FiscalYearRules::requiresCalendarYear(LegalForm::Ab))->toBeFalse()
        ->and(FiscalYearRules::requiresCalendarYearWithNaturalPartner(LegalForm::Hb))->toBeTrue()
        ->and(FiscalYearRules::requiresCalendarYearWithNaturalPartner(LegalForm::Kb))->toBeTrue()
        ->and(FiscalYearRules::requiresCalendarYearWithNaturalPartner(LegalForm::Ab))->toBeFalse();
});

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
