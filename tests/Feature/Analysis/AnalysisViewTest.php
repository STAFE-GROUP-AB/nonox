<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\SaveBudgetLines;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Accounting\Reports\LedgerReports;
use App\Domain\Analysis\AnalysisDefinition;
use App\Domain\Analysis\DatasetCatalogue;
use App\Domain\Analysis\Datasets\LedgerDataset;
use App\Livewire\Analysis\AnalysisPage;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\DimensionScopeFixture;

/*
 * ADR-081 gap 7, E17: the analysis view. A fixed catalogue of datasets, a
 * pivot of rows and columns, filters, a period with a comparison, and a
 * drill-down to the records. Everything typed into the URL is checked
 * against the catalogue.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    $this->fixture = DimensionScopeFixture::make();
    $this->actingAs($this->fixture->owner);
    CurrentCompany::forget();
});

function e17Page(array $set = [])
{
    $page = Livewire::test(AnalysisPage::class)->set('from', '2026-01')->set('to', '2026-12');

    foreach ($set as $property => $value) {
        $page->set($property, $value);
    }

    return $page;
}

/** @return array<string, int> the first column's value per row label's leading word */
function e17RegisterInvoices(DimensionScopeFixture $fixture): void
{
    CurrentCompany::run($fixture->company, function () use ($fixture): void {
        foreach ($fixture->invoices as $invoice) {
            app(RegisterSupplierInvoice::class)($fixture->owner, $invoice);
        }
    });
}

function e17Values($page): array
{
    $result = $page->viewData('result');
    $values = [];

    foreach ($result->rows as $row) {
        $values[explode(' ', $row['label'])[0]] = $result->rowTotals[$row['key']]['v'];
    }

    return $values;
}

it('adds up the posted ledger by account and period', function () {
    $page = e17Page(['source' => 'ledger', 'rows' => ['account'], 'metric' => 'debit']);

    expect(e17Values($page))->toBe(['1930' => 120_000, '3001' => 0, '4010' => 20_000, '5010' => 60_000, '6570' => 5_000]);

    $credits = e17Page(['source' => 'ledger', 'rows' => ['account'], 'metric' => 'credit']);

    expect(e17Values($credits)['3001'])->toBe(120_000);

    $none = e17Page(['rows' => ['account'], 'from' => '2025-01', 'to' => '2025-12']);

    expect($none->viewData('result')->rows)->toBe([]);
});

it('pivots rows against a column, with totals that add up', function () {
    $result = e17Page(['rows' => ['account'], 'column' => 'cost_center', 'metric' => 'debit'])->viewData('result');
    $labels = array_column($result->columns, 'label');

    expect($labels)->toContain('10 Butik Norr', '20 Butik Syd', 'Ingen enhet')
        ->and($result->grandTotal['v'])->toBe(205_000)
        ->and(array_sum(array_column($result->columnTotals, 'v')))->toBe(205_000)
        ->and(array_sum(array_column($result->rowTotals, 'v')))->toBe(205_000);
});

it('filters on a dimension and groups on two levels', function () {
    $south = $this->fixture->south->id;
    $page = e17Page(['rows' => ['account', 'cost_center'], 'metric' => 'debit', 'filterDimension1' => 'cost_center', 'filterValues1' => [(string) $south]]);
    $result = $page->viewData('result');

    expect(array_column($result->rows, 'label'))->toBe(['3001 Försäljning inom Sverige, 25 % moms / 20 Butik Syd', '4010 Inköp material och varor / 20 Butik Syd', '5010 Lokalhyra / 20 Butik Syd'])
        ->and($result->grandTotal['v'])->toBe(50_000);
});

it('compares with the same months a year earlier', function () {
    $page = e17Page(['rows' => ['account'], 'metric' => 'debit', 'compare' => true, 'from' => '2027-03', 'to' => '2027-03']);
    $result = $page->viewData('result');
    $row = collect($result->rows)->firstWhere('label', '1930 Företagskonto/checkkonto/affärskonto');

    expect($result->compared)->toBeTrue()
        ->and($result->cells[$row['key']]['']['v'])->toBe(0)
        ->and($result->cells[$row['key']]['']['p'])->toBe(120_000);
});

it('counts closing and depreciation vouchers like the reports, and leaves out drafts', function () {
    CurrentCompany::run($this->fixture->company, function (): void {
        $owner = $this->fixture->owner;
        $draft = app(SaveDraftVoucher::class)($owner, $this->fixture->company, [
            'voucher_date' => '2026-03-31', 'description' => 'Bokslut', 'series' => 'A',
            'rows' => [['account' => 8999, 'debit_ore' => 900_000], ['account' => 2099, 'credit_ore' => 900_000]],
        ]);
        $draft->forceFill(['source' => 'CLOSING'])->save();
        app(PostVoucher::class)($owner, $draft->fresh() ?? $draft);
        app(SaveDraftVoucher::class)($owner, $this->fixture->company, [
            'voucher_date' => '2026-03-31', 'description' => 'Utkast', 'series' => 'A',
            'rows' => [['account' => 6570, 'debit_ore' => 777_000], ['account' => 1930, 'credit_ore' => 777_000]],
        ]);
    });

    expect(e17Values(e17Page(['rows' => ['account'], 'metric' => 'debit'])))->toBe(['1930' => 120_000, '2099' => 0, '3001' => 0, '4010' => 20_000, '5010' => 60_000, '6570' => 5_000, '8999' => 900_000]);
});

it('adds up the same result as the Resultatrapport, depreciation entries included', function () {
    CurrentCompany::run($this->fixture->company, function (): void {
        $owner = $this->fixture->owner;
        $draft = app(SaveDraftVoucher::class)($owner, $this->fixture->company, [
            'voucher_date' => '2026-03-31', 'description' => 'Avskrivning', 'series' => 'A',
            'rows' => [['account' => 7832, 'debit_ore' => 250_000], ['account' => 1229, 'credit_ore' => 250_000]],
        ]);
        $draft->forceFill(['source' => 'CLOSING'])->save();
        app(PostVoucher::class)($owner, $draft->fresh() ?? $draft);
    });

    $page = e17Page(['rows' => ['account'], 'metric' => 'net', 'from' => '2026-03', 'to' => '2026-03']);
    $result = $page->viewData('result');
    $ledger = [];

    foreach ($result->rows as $row) {
        $number = (int) explode(' ', $row['label'])[0];

        if ($number >= 3000 && $number <= 8999) {
            $ledger[$number] = -$result->rowTotals[$row['key']]['v'];
        }
    }

    $year = FiscalYear::query()->firstOrFail();
    $query = new LedgerQuery($year, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));
    $statement = CurrentCompany::run($this->fixture->company, fn (): array => app(LedgerReports::class)->incomeStatement($query));
    $report = [];

    foreach ($statement['groups'] as $group) {
        foreach ($group['rows'] as $line) {
            $report[$line['number']] = $line['amount'];
        }
    }

    ksort($ledger);
    ksort($report);

    expect($ledger)->toBe($report)
        ->and($ledger[7832])->toBe(-250_000)
        ->and(array_sum($ledger))->toBe($statement['result']);
});

it('sorts by label or by the largest total first', function () {
    $byTotal = e17Page(['rows' => ['account'], 'metric' => 'debit', 'sort' => 'total'])->viewData('result');

    expect(array_map(fn (array $row): string => explode(' ', $row['label'])[0], $byTotal->rows))->toBe(['1930', '5010', '4010', '6570', '3001']);
});

it('opens a cell into the records behind it, linking to the voucher', function () {
    $page = e17Page(['rows' => ['account'], 'metric' => 'debit'])->call('drill', '5010', '');
    $drill = $page->viewData('drill');

    expect(array_column($drill['rows'], 'cells'))->toHaveCount(2)
        ->and($drill['rows'][0]['cells'][2])->toBe('5010')
        ->and($drill['rows'][0]['url'])->toContain('/verifikationer/');

    $page->assertSee('Poster bakom')->call('closeDrill')->assertDontSee('Poster bakom');
});

it('falls back to the catalogue for anything it does not name', function () {
    $dataset = new LedgerDataset;
    $definition = AnalysisDefinition::normalise($dataset, [
        'rows' => ['account; DROP TABLE users', 'cost_center', 'cost_center', 'project', 'period'],
        'column' => 'nonsense',
        'measure' => '1); DELETE FROM vouchers; --',
        'from' => '2026-13',
        'to' => 'tomorrow',
        'filters' => ['account' => ['4010'], 'cost_center' => ['1'], 'project' => ['1'], 'series' => ['A']],
        'sort' => 'rand()',
    ], CarbonImmutable::parse('2026-06-15'));

    expect($definition->rows)->toBe(['cost_center', 'project'])
        ->and($definition->column)->toBeNull()
        ->and($definition->measure)->toBe('net')
        ->and($definition->from->format('Y-m'))->toBe('2025-07')
        ->and($definition->to->format('Y-m'))->toBe('2026-06')
        ->and(array_keys($definition->filters))->toBe(['account', 'cost_center'])
        ->and($definition->sort)->toBe('label');
});

it('caps the period at sixty months and turns the comparison off when months are the grouping', function () {
    $definition = AnalysisDefinition::normalise(new LedgerDataset, ['rows' => ['period'], 'from' => '2000-01', 'to' => '2026-06', 'compare' => true], CarbonImmutable::parse('2026-06-15'));

    expect($definition->from->format('Y-m'))->toBe('2021-07')
        ->and($definition->compare)->toBeFalse();
});

it('binds a filter value instead of writing it into the query', function () {
    $result = e17Page(['rows' => ['account'], 'filterDimension1' => 'account', 'filterValues1' => ["4010') OR 1=1 --"]])->viewData('result');

    expect($result->rows)->toBe([])
        ->and(DB::table('vouchers')->count())->toBe(5);
});

it('refuses a member without the permission', function () {
    $approver = Bookkeeping::member($this->fixture->company, 'approver');
    $this->actingAs($approver);
    CurrentCompany::forget();

    $this->get(route('analysis.index'))->assertForbidden();
});

it('shows the page to those who may, with the catalogue of their datasets', function () {
    $this->get(route('analysis.index'))->assertOk()->assertSee('Bokföringsrader');

    $keys = array_keys(DatasetCatalogue::for($this->fixture->owner, $this->fixture->company));

    // Payroll is not on for this company, so its permission does not exist.
    expect($keys)->toBe(['ledger', 'budget', 'customer_invoices', 'invoice_rows', 'supplier_invoices']);
});

it('reads the active budget only and groups its lines', function () {
    CurrentCompany::run($this->fixture->company, function (): void {
        $owner = $this->fixture->owner;
        $year = FiscalYear::query()->firstOrFail();
        $version = app(CreateBudgetVersion::class)($owner, $this->fixture->company, ['fiscal_year_id' => $year->id, 'name' => 'Budget', 'start' => CreateBudgetVersion::EMPTY]);
        $account = (int) Account::query()->where('number', 3001)->value('id');
        app(SaveBudgetLines::class)($owner, $version, [
            ['account_id' => $account, 'cost_center_id' => $this->fixture->north->id, 'project_id' => null, 'amounts' => ['2026-03' => -400_000, '2026-04' => -100_000]],
        ]);
    });

    $page = e17Page(['source' => 'budget', 'rows' => ['account'], 'column' => 'period']);
    $result = $page->viewData('result');

    expect(array_column($result->columns, 'label'))->toBe(['2026-03', '2026-04'])
        ->and($result->grandTotal['v'])->toBe(-500_000);
});

it('analyses supplier invoices by supplier and account', function () {
    e17RegisterInvoices($this->fixture);

    $result = e17Page(['source' => 'supplier_invoices', 'rows' => ['supplier'], 'column' => 'cost_center'])->viewData('result');

    expect(array_column($result->rows, 'label'))->toBe(['Grossisten AB'])
        ->and($result->grandTotal['v'])->toBe(200_000);

    $drill = e17Page(['source' => 'supplier_invoices', 'rows' => ['account']])->call('drill', '4010', '')->viewData('drill');

    expect($drill['rows'])->toHaveCount(2)
        ->and($drill['rows'][0]['url'])->toContain('/leverantorsfakturor/');
});

it('analyses customer invoices and their rows, credit notes counting against sales', function () {
    $company = $this->fixture->company;
    $owner = $this->fixture->owner;
    $customer = Crm::customer($company, $owner);
    Crm::invoice($owner, $company, $customer, '2026-03-02', [['description' => 'Catering', 'quantity' => '1', 'price' => '2500', 'vat_rate' => 1200]]);
    Crm::invoice($owner, $company, $customer, '2026-04-02', [['description' => 'Catering', 'quantity' => '2', 'price' => '1000', 'vat_rate' => 1200]]);

    $byMonth = e17Page(['source' => 'customer_invoices', 'rows' => ['customer'], 'column' => 'period', 'metric' => 'net'])->viewData('result');
    $open = e17Page(['source' => 'customer_invoices', 'rows' => ['customer'], 'metric' => 'open'])->viewData('result');
    $rows = e17Page(['source' => 'invoice_rows', 'rows' => ['article'], 'metric' => 'net'])->viewData('result');

    expect(array_column($byMonth->columns, 'label'))->toBe(['2026-03', '2026-04'])
        ->and($byMonth->grandTotal['v'])->toBe(450_000)
        ->and($open->grandTotal['v'])->toBe(450_000 + 54_000)
        ->and(array_column($rows->rows, 'label'))->toBe(['Utan artikel'])
        ->and($rows->grandTotal['v'])->toBe(450_000);

    $drill = e17Page(['source' => 'customer_invoices', 'rows' => ['customer']])->call('drill', (string) $customer->id, '')->viewData('drill');

    expect($drill['rows'])->toHaveCount(2)->and($drill['rows'][0]['url'])->toContain('/fakturor/');
});

it('runs the same number of queries whatever the amount of data', function () {
    $count = function (): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        e17Page(['rows' => ['account', 'cost_center'], 'column' => 'period', 'metric' => 'debit', 'compare' => false, 'filterDimension1' => 'account', 'filterValues1' => ['1930']])->call('drill', '1930|0', '2026-03');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $short = $count();

    CurrentCompany::run($this->fixture->company, function (): void {
        foreach (range(1, 30) as $i) {
            app(PostVoucher::class)($this->fixture->owner, app(SaveDraftVoucher::class)($this->fixture->owner, $this->fixture->company, [
                'voucher_date' => '2026-0'.(($i % 9) + 1).'-10', 'description' => "Bulk {$i}", 'series' => 'A',
                'rows' => [
                    ['account' => 1930, 'debit_ore' => 1000 * $i, 'cost_center' => $i % 2 === 0 ? '10' : '20'],
                    ['account' => 3001, 'credit_ore' => 1000 * $i],
                ],
            ]));
        }
    });

    expect(Voucher::query()->count())->toBeGreaterThan(30)
        ->and($count())->toBe($short);
});
