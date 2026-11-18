<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\SaveBudgetLines;
use App\Actions\Company\SetDimensionScope;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Domain\Analysis\DatasetCatalogue;
use App\Livewire\Analysis\AnalysisPage;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScope;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\DimensionScopeFixture;

/*
 * ADR-081 gap 10 for the analysis view (E17): a member limited to cost
 * centres or projects reads only rows on their own units, never payroll
 * vouchers nor the personnel accounts 7000-7699, sees another unit only as
 * "Annan enhet", and cannot rebuild an out-of-scope figure by choosing
 * rows, columns, filters or periods.
 *
 * Fixture (all 2026-03-01): scope is cost centre 10 and project P1.
 * Inside: 50 000 sale on 10, 20 000 material on P1 (cost centre 20 on the
 * same row), 30 000 rent on 10. Outside: 70 000 sale on 20, 30 000 rent on
 * 20, 5 000 bank fee without a unit.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    $this->fixture = DimensionScopeFixture::make();

    CurrentCompany::run($this->fixture->company, function (): void {
        $owner = $this->fixture->owner;
        $company = $this->fixture->company;
        $post = function (string $description, array $rows, ?string $source) use ($owner, $company): void {
            $draft = app(SaveDraftVoucher::class)($owner, $company, ['voucher_date' => '2026-03-20', 'description' => $description, 'series' => 'A', 'rows' => $rows]);

            if ($source !== null) {
                $draft->forceFill(['source' => $source])->save();
            }

            app(PostVoucher::class)($owner, $draft->fresh() ?? $draft);
        };

        // Pay on the member's own unit: by payroll, and by SIE import.
        $post('Lön mars', [['account' => 7010, 'debit_ore' => 3_000_000, 'cost_center' => '10'], ['account' => 1930, 'credit_ore' => 3_000_000]], 'PAYROLL');
        $post('Lön via SIE', [['account' => 7010, 'debit_ore' => 2_500_000, 'cost_center' => '10'], ['account' => 1930, 'credit_ore' => 2_500_000]], 'IMPORT_SIE');
        // A non-personnel cost booked by payroll (a benefit): still never shown.
        $post('Förmån', [['account' => 5800, 'debit_ore' => 111_000, 'cost_center' => '10'], ['account' => 1930, 'credit_ore' => 111_000]], 'PAYROLL');

        foreach ($this->fixture->invoices as $invoice) {
            app(RegisterSupplierInvoice::class)($owner, $invoice);
        }

        $year = FiscalYear::query()->firstOrFail();
        $version = app(CreateBudgetVersion::class)($owner, $company, ['fiscal_year_id' => $year->id, 'name' => 'Budget', 'start' => CreateBudgetVersion::EMPTY]);
        $account = fn (int $number): int => (int) Account::query()->where('number', $number)->value('id');
        app(SaveBudgetLines::class)($owner, $version, [
            ['account_id' => $account(3001), 'cost_center_id' => $this->fixture->north->id, 'project_id' => null, 'amounts' => ['2026-03' => -400_000]],
            ['account_id' => $account(3001), 'cost_center_id' => $this->fixture->south->id, 'project_id' => null, 'amounts' => ['2026-03' => -900_000]],
            ['account_id' => $account(7010), 'cost_center_id' => $this->fixture->north->id, 'project_id' => null, 'amounts' => ['2026-03' => 2_000_000]],
        ]);
    });

    $this->member = $this->fixture->member();
    $this->actingAs($this->member);
    CurrentCompany::forget();
});

function e17Member(array $set = [])
{
    $page = Livewire::test(AnalysisPage::class)->set('from', '2026-01')->set('to', '2026-12');

    foreach ($set as $property => $value) {
        $page->set($property, $value);
    }

    return $page;
}

/** @return array<string, int> */
function e17ByRow($page): array
{
    $result = $page->viewData('result');

    return collect($result->rows)->mapWithKeys(fn (array $row): array => [$row['label'] => $result->rowTotals[$row['key']]['v']])->all();
}

it('offers a limited member only the datasets that hold them inside the scope', function () {
    expect(array_keys(DatasetCatalogue::for($this->member, $this->fixture->company->fresh())))->toBe(['ledger', 'budget', 'supplier_invoices']);

    $manager = Bookkeeping::member($this->fixture->company, 'unit_manager');
    app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $manager->id, [$this->fixture->north->id], []);
    DimensionScope::forget();

    expect(array_keys(DatasetCatalogue::for($manager->fresh(), $this->fixture->company->fresh())))->toBe(['ledger', 'budget']);
});

it('refuses customer invoices, invoice rows and payroll even when asked for by name', function () {
    foreach (['customer_invoices', 'invoice_rows', 'payroll'] as $source) {
        $page = e17Member(['source' => $source]);

        expect($page->viewData('dataset')->key())->toBe('ledger');
    }
});

it('reads only rows on the member\'s own units, by their own part of a voucher', function () {
    $page = e17Member(['rows' => ['account'], 'metric' => 'net']);

    expect(e17ByRow($page))->toBe([
        '3001 Försäljning inom Sverige, 25 % moms' => -50_000,
        '4010 Inköp material och varor' => 120_000,
        '5010 Lokalhyra' => 30_000,
    ]);
});

it('shows another unit as Annan enhet, never by name, number or amount of its own rows', function () {
    $page = e17Member(['rows' => ['cost_center'], 'column' => 'account', 'metric' => 'debit']);
    $labels = array_column($page->viewData('result')->rows, 'label');

    expect($labels)->toContain('10 Butik Norr', 'Annan enhet')
        ->and($labels)->not->toContain('20 Butik Syd');

    $page->assertDontSee('Butik Syd')->assertDontSee('Grossisten AB');

    // The "other unit" row holds only the member's project row on cost centre 20, not the 100 000 booked there outside the scope.
    $result = $page->viewData('result');
    $other = collect($result->rows)->firstWhere('label', 'Annan enhet');

    expect($result->rowTotals[$other['key']]['v'])->toBe(20_000);
});

it('leaves out payroll vouchers, personnel accounts and the budget of personnel for a limited member only', function () {
    $member = e17Member(['rows' => ['account'], 'metric' => 'debit']);

    expect(array_keys(e17ByRow($member)))->not->toContain('7010 Löner till kollektivanställda')
        ->and(collect(array_keys(e17ByRow($member)))->contains(fn (string $label): bool => str_starts_with($label, '7010') || str_starts_with($label, '5800')))->toBeFalse();

    $member->assertDontSee('Lön mars')->assertDontSee('3 000 000');

    $budget = e17Member(['source' => 'budget', 'rows' => ['account']]);

    expect(array_keys(e17ByRow($budget)))->toBe(['3001 Försäljning inom Sverige, 25 % moms'])
        ->and(array_values(e17ByRow($budget)))->toBe([-400_000]);

    $this->actingAs($this->fixture->owner);
    CurrentCompany::forget();
    $owner = e17Member(['rows' => ['account'], 'metric' => 'debit']);

    expect(collect(array_keys(e17ByRow($owner)))->contains(fn (string $label): bool => str_starts_with($label, '7010')))->toBeTrue()
        ->and(array_sum(e17ByRow($owner)))->toBeGreaterThan(5_000_000);
});

it('cannot rebuild an out-of-scope figure with filters, columns or periods', function () {
    $south = (string) $this->fixture->south->id;

    // A filter on the unit outside the scope matches nothing: its key is masked to -1 and its id never matches.
    $filtered = e17Member(['rows' => ['account'], 'metric' => 'debit', 'filterDimension1' => 'cost_center', 'filterValues1' => [$south]]);

    expect($filtered->viewData('result')->rows)->toBe([]);

    // The masked key -1 is the member's own project row, nothing else.
    $masked = e17Member(['rows' => ['account'], 'metric' => 'debit', 'filterDimension1' => 'cost_center', 'filterValues1' => ['-1']])->viewData('result');

    expect($masked->grandTotal['v'])->toBe(20_000);

    // No grouping adds up to more than the member's own rows: not by unit, month, series or account class, nor all of them as a pivot.
    foreach (['cost_center', 'project', 'period', 'series', 'account_class', 'account_group'] as $dimension) {
        $result = e17Member(['rows' => [$dimension], 'column' => 'account', 'metric' => 'credit'])->viewData('result');

        expect($result->grandTotal['v'])->toBe(50_000)
            ->and(e17Member(['rows' => [$dimension], 'metric' => 'rows'])->viewData('result')->grandTotal['v'])->toBe(4);
    }

    // A wider period adds nothing: the member's rows are the same in any period that holds them.
    expect(e17Member(['rows' => ['account'], 'metric' => 'net', 'from' => '2026-03', 'to' => '2026-03'])->viewData('result')->grandTotal['v'])->toBe(100_000)
        ->and(e17Member(['rows' => ['account'], 'metric' => 'net', 'from' => '2022-01', 'to' => '2027-12'])->viewData('result')->grandTotal['v'])->toBe(100_000);
});

it('does not let a cell of the member open another unit\'s records', function () {
    $page = e17Member(['rows' => ['cost_center'], 'metric' => 'debit'])->call('drill', '-1', '');
    $drill = $page->viewData('drill');

    expect($drill['rows'])->toHaveCount(1)
        ->and($drill['rows'][0]['cells'][4])->toBe('')
        ->and($drill['rows'][0]['cells'][5])->toBe('P1')
        ->and($drill['rows'][0]['url'])->toBeNull();

    $south = e17Member(['rows' => ['cost_center'], 'metric' => 'debit'])->call('drill', (string) $this->fixture->south->id, '')->viewData('drill');

    expect($south['rows'])->toBe([]);
});

it('reads only the supplier invoices the member\'s own lists show', function () {
    $page = e17Member(['source' => 'supplier_invoices', 'rows' => ['supplier'], 'column' => 'cost_center']);
    $result = $page->viewData('result');

    expect($result->grandTotal['v'])->toBe(100_000)
        ->and(array_column($result->columns, 'label'))->toBe(['10 Butik Norr']);
});

it('keeps the filter picker to the member\'s own units and accounts', function () {
    $page = e17Member(['rows' => ['account'], 'filterDimension1' => 'cost_center', 'filterDimension2' => 'account']);
    $options = $page->viewData('filterOptions');

    expect(array_values($options[1]))->toBe(['Ingen enhet', '10 Butik Norr', 'Annan enhet'])
        ->and(array_keys($options[2]))->not->toContain('7010', '7699');
});

it('answers 404 for a limited member without analysis:view and opens the page for one with it', function () {
    $this->get(route('analysis.index'))->assertOk();

    $approver = $this->fixture->member('approver');
    $this->actingAs($approver);
    CurrentCompany::forget();

    $this->get(route('analysis.index'))->assertNotFound();
});

it('shows the description of a voucher in the drill-down only when the whole voucher is inside the scope', function () {
    // "Hyra delad" has rows on cost centre 10 (the member's own) and 20: the member may not open it, so only the row's own text shows.
    $page = e17Member(['rows' => ['account'], 'metric' => 'debit'])->call('drill', '5010', '');
    $rows = $page->viewData('drill')['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['cells'][3])->toBe('');

    $page->assertDontSee('Hyra delad');

    // "Kassa norr" lies wholly on cost centre 10: its description is shown.
    $inside = e17Member(['rows' => ['account'], 'metric' => 'credit'])->call('drill', '3001', '')->viewData('drill')['rows'];

    expect(array_column(array_column($inside, 'cells'), 3))->toContain('Kassa norr');
});
