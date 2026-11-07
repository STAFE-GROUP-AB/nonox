<?php

declare(strict_types=1);

use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\K2Schedule;
use App\Domain\AnnualReport\K3Schedule;
use App\Domain\AnnualReport\PrintedFigures;
use App\Domain\AnnualReport\UnbalancedBalanceSheet;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

/*
 * S1-P6 review: on the K3 path a real imbalance is never hidden on Övriga
 * skulder. BAS 2010 (eget kapital in a sole trader's chart) is on no line of
 * an aktiebolag's balance sheet, so a balance there leaves the sheet apart.
 */

/**
 * @param  class-string<K2Schedule>|class-string<K3Schedule>  $schedule
 * @return array<string, mixed>
 */
function printedSheetFor(FiscalYear $year, string $schedule): array
{
    $accounts = AnnualAccounts::for($year, $schedule);

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
    ], $schedule)['balance'];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
});

it('S1-R8.2 prints a balanced K3 sheet with only rounding differences', function () {
    Bookkeeping::post($this->owner, $this->company, 1_234_567, 5010, 1930, '2026-04-01');
    $sheet = printedSheetFor($this->year, K3Schedule::class);

    expect($sheet['assets'])->toBe($sheet['equity_liabilities']);
});

it('S1-R8.2 refuses a K3 sheet that does not balance, with the exact difference, instead of booking it on Övriga skulder', function () {
    if (! Account::query()->where('number', 2010)->exists()) {
        Account::create(['number' => 2010, 'name' => 'Eget kapital', 'type' => AccountType::forNumber(2010), 'is_active' => true]);
    }

    Bookkeeping::post($this->owner, $this->company, 123_456, 1930, 2010, '2026-05-01');

    try {
        printedSheetFor($this->year, K3Schedule::class);
        $this->fail('The unbalanced K3 sheet was printed.');
    } catch (UnbalancedBalanceSheet $exception) {
        expect($exception->difference)->toBe(123_456)
            ->and($exception->column)->toBe('amount');
    }

    // K2 keeps its behaviour (ADR-059 follow-up): the difference goes on Övriga skulder.
    $k2 = printedSheetFor($this->year, K2Schedule::class);
    expect($k2['assets'])->toBe($k2['equity_liabilities']);
});
