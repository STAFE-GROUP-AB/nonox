<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Accounting\Reports\LedgerReports;
use App\Livewire\Accounting\Reports;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
});

/** @param  list<array<string, mixed>>  $rows */
function postRows(User $user, Company $company, string $date, array $rows, string $text = 'Händelse'): void
{
    app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $company, ['voucher_date' => $date, 'description' => $text, 'rows' => $rows]));
}

function opening(FiscalYear $year, int $number, int $ore): void
{
    OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => Account::query()->where('number', $number)->value('id'), 'amount_ore' => $ore]);
}

/**
 * A restaurant's year: share capital in the bank, two lunches sold in
 * Malmö and Lund, and rent paid.
 */
function restaurantYear(User $owner, Company $company, FiscalYear $year): void
{
    opening($year, 1930, 5_000_000);
    opening($year, 2081, -5_000_000);
    CostCenter::create(['code' => '10', 'name' => 'Malmö']);
    CostCenter::create(['code' => '20', 'name' => 'Lund']);

    postRows($owner, $company, '2026-02-10', [
        ['account' => 1930, 'debit_ore' => 112_000],
        ['account' => 3002, 'credit_ore' => 100_000, 'cost_center' => '10'],
        ['account' => 2621, 'credit_ore' => 12_000],
    ], 'Lunch Malmö');
    postRows($owner, $company, '2026-05-10', [
        ['account' => 1930, 'debit_ore' => 56_000],
        ['account' => 3002, 'credit_ore' => 50_000, 'cost_center' => '20'],
        ['account' => 2621, 'credit_ore' => 6_000],
    ], 'Lunch Lund');
    postRows($owner, $company, '2026-05-31', [
        ['account' => 5010, 'debit_ore' => 40_000, 'cost_center' => '10'],
        ['account' => 1930, 'credit_ore' => 40_000],
    ], 'Hyra Malmö');
}

it('makes a trial balance that sums to zero', function () {
    restaurantYear($this->owner, $this->company, $this->year);

    $balance = app(LedgerReports::class)->trialBalance(LedgerQuery::forYear($this->year));
    $byNumber = collect($balance['rows'])->keyBy('number');

    expect($byNumber[1930])->toMatchArray(['opening' => 5_000_000, 'debit' => 168_000, 'credit' => 40_000, 'closing' => 5_128_000])
        ->and($byNumber[3002]['closing'])->toBe(-150_000)
        ->and($balance['totals']['closing'])->toBe(0)
        ->and($balance['totals']['debit'])->toBe($balance['totals']['credit']);
});

it('opens a later period with everything posted before it', function () {
    restaurantYear($this->owner, $this->company, $this->year);

    $query = new LedgerQuery($this->year, CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'));
    $rows = collect(app(LedgerReports::class)->trialBalance($query)['rows'])->keyBy('number');

    expect($rows[1930])->toMatchArray(['opening' => 5_112_000, 'debit' => 56_000, 'credit' => 40_000, 'closing' => 5_128_000])
        ->and($rows[3002])->toMatchArray(['opening' => -100_000, 'credit' => 50_000]);
});

it('shows the income statement with revenue positive and the result', function () {
    restaurantYear($this->owner, $this->company, $this->year);

    $statement = app(LedgerReports::class)->incomeStatement(LedgerQuery::forYear($this->year));
    $groups = collect($statement['groups'])->keyBy('key');

    expect($groups['revenue']['total'])->toBe(150_000)
        ->and($groups['external']['total'])->toBe(-40_000)
        ->and($groups['other_operating']['subtotal'])->toMatchArray(['label' => 'Rörelseresultat', 'amount' => 110_000])
        ->and($statement['result'])->toBe(110_000);
});

it('compares with the same period of the year before', function () {
    $previous = FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    postRows($this->owner, $this->company, '2025-03-01', [['account' => 1930, 'debit_ore' => 70_000], ['account' => 3002, 'credit_ore' => 70_000]]);
    postRows($this->owner, $this->company, '2025-09-01', [['account' => 1930, 'debit_ore' => 1_000], ['account' => 3002, 'credit_ore' => 1_000]]);
    restaurantYear($this->owner, $this->company, $this->year);

    $query = new LedgerQuery($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-06-30'));
    $statement = app(LedgerReports::class)->incomeStatement($query, LedgerReports::previousPeriod($query));

    expect($statement['compare_result'])->toBe(70_000)
        ->and($statement['compare_label'])->toBe('2025-01-01 – 2025-06-30')
        ->and($previous->id)->not->toBe($this->year->id);
});

it('balances the balance sheet with the result not yet booked', function () {
    restaurantYear($this->owner, $this->company, $this->year);

    $sheet = app(LedgerReports::class)->balanceSheet(LedgerQuery::forYear($this->year));

    expect($sheet['sides']['assets']['closing'])->toBe(5_128_000)
        ->and($sheet['unbooked_result']['closing'])->toBe(110_000)
        ->and($sheet['sides']['equity']['closing'])->toBe($sheet['sides']['assets']['closing']);
});

it('reports one cost centre at a time', function () {
    restaurantYear($this->owner, $this->company, $this->year);

    $malmo = CostCenter::query()->where('code', '10')->value('id');
    $statement = app(LedgerReports::class)->incomeStatement(new LedgerQuery($this->year, $this->year->start_date, $this->year->end_date, costCenterId: $malmo));

    expect($statement['result'])->toBe(60_000);
});

it('keeps a running balance per account in the general ledger', function () {
    restaurantYear($this->owner, $this->company, $this->year);

    $ledger = app(LedgerReports::class)->generalLedger(LedgerQuery::forYear($this->year), 1930);

    expect($ledger)->toHaveCount(1)
        ->and($ledger[0]['opening'])->toBe(5_000_000)
        ->and(array_column($ledger[0]['rows'], 'balance'))->toBe([5_112_000, 5_168_000, 5_128_000])
        ->and(array_column($ledger[0]['rows'], 'reference'))->toBe(['A1', 'A2', 'A3'])
        ->and($ledger[0]['closing'])->toBe(5_128_000);
});

it('lists the vouchers of the period, filtered by cost centre', function () {
    restaurantYear($this->owner, $this->company, $this->year);

    $lund = CostCenter::query()->where('code', '20')->value('id');

    expect(app(LedgerReports::class)->voucherList(LedgerQuery::forYear($this->year))->map->reference()->all())->toBe(['A1', 'A2', 'A3'])
        ->and(app(LedgerReports::class)->voucherList(new LedgerQuery($this->year, $this->year->start_date, $this->year->end_date, costCenterId: $lund))->map->reference()->all())->toBe(['A2']);
});

it('shows every report on the page and keeps the choices in the URL', function (string $report, string $text) {
    restaurantYear($this->owner, $this->company, $this->year);

    $this->get(route('accounting.reports', ['rapport' => $report, 'kst' => '10']))->assertOk()->assertSee($text);
})->with([
    'saldobalans' => ['saldobalans', 'Saldobalans'],
    'resultat' => ['resultat', 'Rörelseresultat'],
    'balans' => ['balans', 'Summa tillgångar'],
    'huvudbok' => ['huvudbok', 'Ingående saldo'],
    'verifikationer' => ['verifikationer', 'Lunch Malmö'],
]);

it('switches report and year in the component', function () {
    restaurantYear($this->owner, $this->company, $this->year);

    Livewire::test(Reports::class)
        ->call('show', 'resultat')
        ->assertSet('rapport', 'resultat')
        ->assertSee('Beräknat resultat')
        ->set('jamfor', true)
        ->assertSee('Det finns inget föregående räkenskapsår att jämföra med.');
});

it('downloads the report for Excel', function () {
    restaurantYear($this->owner, $this->company, $this->year);

    $response = $this->get(route('accounting.reports.csv', ['rapport' => 'saldobalans']))->assertOk();
    $csv = $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($csv)->toStartWith("\xEF\xBB\xBFKonto;Namn;Ingående;Debet;Kredit;Utgående")
        ->and($csv)->toContain('1930;Företagskonto/checkkonto/affärskonto;50000,00;1680,00;400,00;51280,00')
        ->and($csv)->toContain('3002;"Försäljning inom Sverige, 12 % moms";0,00;0,00;1500,00;-1500,00');
});

it('keeps reports to those who may see them', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'approver'));

    $this->get(route('accounting.reports'))->assertForbidden();
    $this->get(route('accounting.reports.csv'))->assertForbidden();
});

it('runs the same number of queries however much is booked', function () {
    restaurantYear($this->owner, $this->company, $this->year);

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        foreach (['saldobalans', 'resultat', 'balans', 'huvudbok', 'verifikationer'] as $report) {
            $this->get(route('accounting.reports', ['rapport' => $report]))->assertOk();
        }

        return count(DB::getQueryLog());
    };

    $count();
    $before = $count();

    foreach (range(1, 15) as $day) {
        postRows($this->owner, $this->company, sprintf('2026-07-%02d', $day), [
            ['account' => 1930, 'debit_ore' => 1_000],
            ['account' => 3002, 'credit_ore' => 1_000, 'cost_center' => $day % 2 ? '10' : '20'],
        ]);
    }

    expect($count())->toBe($before);
});
