<?php

declare(strict_types=1);

use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\K3AnnualReportDocument;
use App\Domain\AnnualReport\K3Schedule;
use App\Models\Account;
use App\Models\VoucherNumberCounter;
use App\Models\VoucherSeries;
use Illuminate\Support\Facades\DB;
use Tests\Support\K3DocumentFixture;

/*
 * S1-P14 (ADR-059 A.5, spec Part 2): the K3 annual report of a year with
 * 50 000 posted vouchers (200 000 rows) is read and rendered in under 3 s on
 * the reference setup (Linux, PostgreSQL, as CI); on a developer Mac the test
 * reports the time and fails only above 9 s, because Herd on macOS was
 * measured far slower per query than the Linux server.
 *
 * Group `performance`. The directory is in no PHPUnit test suite, so the
 * default run (`vendor/bin/pest`) never picks it up; run it with
 * `vendor/bin/pest tests/Performance`. Like every test it works inside the
 * test's transaction (DatabaseTransactions) and nothing is committed.
 */

const K3_PERFORMANCE_VOUCHERS = 50_000;

/**
 * Seeds the vouchers in bulk the way the triggers allow: drafts with their
 * rows first, then one UPDATE that posts them all, so
 * vouchers_check_balance_on_post checks every voucher's balance as
 * PostVoucher's update does. The hash is a placeholder (the ledger chain is
 * not what is measured; ledger:verify is not run on this data). Every
 * voucher: 1930 debit 8,50, 4010 debit 4,00, 3001 credit 10,00, 2611 credit
 * 2,50, on the year's days in turn, numbered on from the series counter.
 */
function seedK3PerformanceVouchers(object $test, int $count): void
{
    $year = $test->year;
    $series = VoucherSeries::query()->where('code', 'A')->firstOrFail();
    $counter = VoucherNumberCounter::query()->where('fiscal_year_id', $year->id)->where('series_id', $series->id)->first();
    $first = $counter === null ? 1 : (int) $counter->next_number;
    $accounts = Account::query()->whereIn('number', [1930, 2611, 3001, 4010])->pluck('id', 'number');
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
         CROSS JOIN (VALUES (?::bigint, 850, 0), (?::bigint, 400, 0), (?::bigint, 0, 1000), (?::bigint, 0, 250)) AS template (account_id, debit_ore, credit_ore)
         WHERE vouchers.fiscal_year_id = ? AND vouchers.series_id = ? AND vouchers.status = 'DRAFT' AND vouchers.number >= ?
        SQL, [$accounts[1930], $accounts[4010], $accounts[3001], $accounts[2611], $year->id, $series->id, $first]);

    DB::update(<<<'SQL'
        UPDATE vouchers
           SET status = 'POSTED', posted_at = now(), posted_by = ?, hash = encode(sha256(id::text::bytea), 'hex')
         WHERE fiscal_year_id = ? AND series_id = ? AND status = 'DRAFT' AND number >= ?
        SQL, [$test->owner->id, $year->id, $series->id, $first]);

    VoucherNumberCounter::query()->updateOrCreate(['fiscal_year_id' => $year->id, 'series_id' => $series->id], ['next_number' => $first + $count]);
}

/** Seconds a callable takes, and its result. */
function k3PerformanceTimed(callable $run): array
{
    $start = hrtime(true);
    $result = $run();

    return [(hrtime(true) - $start) / 1e9, $result];
}

it('reads and renders the K3 annual report of a year with 50 000 vouchers within the budget', function () {
    K3DocumentFixture::load($this);
    K3DocumentFixture::answerQuestions($this);
    $netSalesBefore = AnnualAccounts::for($this->year->refresh(), K3Schedule::class)->netSales();

    [$seeding] = k3PerformanceTimed(fn () => seedK3PerformanceVouchers($this, K3_PERFORMANCE_VOUCHERS));

    DB::flushQueryLog();
    DB::enableQueryLog();
    [$seconds, $html] = k3PerformanceTimed(function (): string {
        $document = K3AnnualReportDocument::for($this->company->refresh(), $this->year->refresh(), K3AnnualReportDocument::report($this->year));

        expect($document->refusals())->toBe([]);

        return $document->html();
    });
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $budget = PHP_OS_FAMILY === 'Darwin' ? 9.0 : 3.0;
    $slowest = collect($queries)->sortByDesc('time')->take(5)
        ->map(fn (array $query): string => sprintf('  %8.1f ms  %s', $query['time'], mb_strimwidth((string) $query['query'], 0, 140, '…')))
        ->implode(PHP_EOL);
    fwrite(STDERR, sprintf(
        '%sK3 report, %d vouchers on %s: %.2f s (budget %.0f s), %d queries, seeding %.1f s. Slowest queries:%s%s%s',
        PHP_EOL, K3_PERFORMANCE_VOUCHERS, PHP_OS_FAMILY, $seconds, $budget, count($queries), $seeding, PHP_EOL, $slowest, PHP_EOL,
    ));

    // The volume reached the report: 50 000 × 10,00 kr more net sales.
    expect(AnnualAccounts::for($this->year->refresh(), K3Schedule::class)->netSales() - $netSalesBefore)->toBe(K3_PERFORMANCE_VOUCHERS * 1_000)
        ->and($html)->toContain('Rapport över förändringar i eget kapital')
        ->and($seconds)->toBeLessThan($budget);
})->group('performance');
