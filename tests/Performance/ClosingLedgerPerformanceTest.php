<?php

declare(strict_types=1);

use App\Domain\Closing\LedgerReconciliation;
use App\Domain\Closing\YearResult;
use App\Models\Account;
use App\Models\VoucherNumberCounter;
use App\Models\VoucherSeries;
use Illuminate\Support\Facades\DB;
use Tests\Support\K3DocumentFixture;

/*
 * The closing reads of the year's ledger (YearResult, LedgerReconciliation,
 * and with them ClosingChecklist and DeferredTaxProposal, which read the rows
 * the same way) read each voucher's rows through the lateral subquery of
 * AnnualAccounts::voucherAccountRows(), as the K3 report does (S1-P14), so a
 * large import with stale statistics cannot make them quadratic. Group
 * `performance`, no PHPUnit suite; run `vendor/bin/pest tests/Performance`.
 * Works inside the test's transaction; nothing is committed.
 */

const CLOSING_PERFORMANCE_VOUCHERS = 50_000;

/**
 * 50 000 posted vouchers in bulk (drafts with rows, then one UPDATE that
 * posts them, as K3ReportPerformanceTest does): 1510 debit 12,50, 3001
 * credit 10,00, 2611 credit 2,50.
 */
function seedClosingPerformanceVouchers(object $test, int $count): void
{
    $year = $test->year;
    $series = VoucherSeries::query()->where('code', 'A')->firstOrFail();
    $counter = VoucherNumberCounter::query()->where('fiscal_year_id', $year->id)->where('series_id', $series->id)->first();
    $first = $counter === null ? 1 : (int) $counter->next_number;
    $accounts = Account::query()->whereIn('number', [1510, 2611, 3001])->pluck('id', 'number');
    $days = $year->start_date->diffInDays($year->end_date) + 1;

    DB::insert(<<<'SQL'
        INSERT INTO vouchers (id, company_id, fiscal_year_id, series_id, number, status, voucher_date, description, source, created_by, created_at, updated_at)
        SELECT gen_random_uuid(), ?, ?, ?, ? + n - 1, 'DRAFT', ?::date + ((n - 1) % ?)::int, 'Prestandatest ' || n, 'MANUAL', ?, now(), now()
          FROM generate_series(1, ?) AS n
        SQL, [$test->company->id, $year->id, $series->id, $first, $year->start_date->toDateString(), (int) $days, $test->owner->id, $count]);

    DB::insert(<<<'SQL'
        INSERT INTO voucher_rows (voucher_id, company_id, account_id, debit_ore, credit_ore, created_at, updated_at)
        SELECT vouchers.id, vouchers.company_id, template.account_id, template.debit_ore, template.credit_ore, now(), now()
          FROM vouchers
         CROSS JOIN (VALUES (?::bigint, 1250, 0), (?::bigint, 0, 1000), (?::bigint, 0, 250)) AS template (account_id, debit_ore, credit_ore)
         WHERE vouchers.fiscal_year_id = ? AND vouchers.series_id = ? AND vouchers.status = 'DRAFT' AND vouchers.number >= ?
        SQL, [$accounts[1510], $accounts[3001], $accounts[2611], $year->id, $series->id, $first]);

    DB::update(<<<'SQL'
        UPDATE vouchers
           SET status = 'POSTED', posted_at = now(), posted_by = ?, hash = encode(sha256(id::text::bytea), 'hex')
         WHERE fiscal_year_id = ? AND series_id = ? AND status = 'DRAFT' AND number >= ?
        SQL, [$test->owner->id, $year->id, $series->id, $first]);

    VoucherNumberCounter::query()->updateOrCreate(['fiscal_year_id' => $year->id, 'series_id' => $series->id], ['next_number' => $first + $count]);
}

it('reads the closing figures of a year with 50 000 vouchers within the budget', function () {
    K3DocumentFixture::load($this);
    $resultBefore = YearResult::of($this->year->refresh())->beforeTaxOre;
    $balanceBefore = LedgerReconciliation::on(LedgerReconciliation::CUSTOMERS, $this->year->end_date)['balance'];

    seedClosingPerformanceVouchers($this, CLOSING_PERFORMANCE_VOUCHERS);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $start = hrtime(true);
    $result = YearResult::of($this->year->refresh());
    $yearResultSeconds = (hrtime(true) - $start) / 1e9;
    $start = hrtime(true);
    $reconciliation = LedgerReconciliation::on(LedgerReconciliation::CUSTOMERS, $this->year->end_date);
    $reconciliationSeconds = (hrtime(true) - $start) / 1e9;
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $budget = PHP_OS_FAMILY === 'Darwin' ? 6.0 : 2.0;
    fwrite(STDERR, sprintf(
        '%sClosing reads, %d vouchers on %s: YearResult %.2f s, LedgerReconciliation %.2f s (budget %.0f s each), %d queries.%s',
        PHP_EOL, CLOSING_PERFORMANCE_VOUCHERS, PHP_OS_FAMILY, $yearResultSeconds, $reconciliationSeconds, $budget, $queries, PHP_EOL,
    ));

    expect($result->beforeTaxOre - $resultBefore)->toBe(-CLOSING_PERFORMANCE_VOUCHERS * 1_000)
        ->and($reconciliation['balance'] - $balanceBefore)->toBe(CLOSING_PERFORMANCE_VOUCHERS * 1_250)
        ->and($yearResultSeconds)->toBeLessThan($budget)
        ->and($reconciliationSeconds)->toBeLessThan($budget);
})->group('performance');
