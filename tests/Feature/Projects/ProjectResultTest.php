<?php

declare(strict_types=1);

/*
 * ADR-081 gap 6, package E7: the project P&L. Revenue, costs by group,
 * contribution and margin for a project's whole life or one fiscal year,
 * against the active budget's lines on the project and the estimate in the
 * register, with drill-down to the posted rows. Read only, through
 * LedgerQuery, in a fixed number of queries.
 */

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Accounting\SaveProjectDetails;
use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\SaveBudgetLines;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Accounting\Dimension;
use App\Domain\Company\AccountingMethod;
use App\Domain\Projects\ProjectResults;
use App\Livewire\Accounting\Reports;
use App\Livewire\Projects\ProjectList;
use App\Livewire\Projects\ProjectResult;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BudgetVersion;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Money\Money;
use App\Support\Navigation\Navigation;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::CostCenter, ['code' => '10', 'name' => 'Verkstad']);
    app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::CostCenter, ['code' => '20', 'name' => 'Kontor']);
    $this->project = app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::Project, ['code' => 'P1', 'name' => 'Ombyggnad kök']);
    $this->other = app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::Project, ['code' => 'P2', 'name' => 'Nytt tak']);
    app(SaveProjectDetails::class)($this->owner, $this->company, $this->project, [
        'status' => 'ACTIVE', 'price_form' => 'FIXED', 'contract_sum' => '200 000', 'estimated_cost' => '150 000',
    ]);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
});

/**
 * A posted voucher; rows are [account, signed öre (debit positive), cost centre, project, text].
 *
 * @param  list<array{0: int, 1: int, 2?: ?string, 3?: ?string, 4?: string}>  $rows
 */
function e7Post(string $date, array $rows, string $description = 'Projekt', ?string $source = null): Voucher
{
    $draft = app(SaveDraftVoucher::class)(test()->owner, test()->company, [
        'voucher_date' => $date,
        'description' => $description,
        'series' => 'A',
        'rows' => array_map(fn (array $row): array => array_filter([
            'account' => $row[0],
            'debit_ore' => max($row[1], 0),
            'credit_ore' => max(-$row[1], 0),
            'cost_center' => $row[2] ?? null,
            'project' => $row[3] ?? null,
            'description' => $row[4] ?? null,
        ], fn (mixed $value): bool => $value !== null), $rows),
    ]);

    if ($source !== null) {
        $draft->forceFill(['source' => $source])->save();
    }

    return app(PostVoucher::class)(test()->owner, $draft->fresh() ?? $draft);
}

/** A typical project: a sale, material, an external cost, wages and interest income, plus rows that are not the project's. */
function e7Project(): void
{
    e7Post('2026-03-10', [[1510, 10_000_000], [3001, -10_000_000, '10', 'P1', 'Etapp 1']], 'Faktura etapp 1');
    e7Post('2026-03-12', [[4010, 2_000_000, '10', 'P1', 'Kakel'], [1930, -2_000_000]], 'Material');
    e7Post('2026-04-02', [[6550, 500_000, null, 'P1'], [1930, -500_000]], 'Konsult');
    e7Post('2026-04-25', [[7010, 3_000_000, '10', 'P1'], [1930, -3_000_000]], 'Löner april');
    e7Post('2026-05-31', [[1930, 10_000], [8310, -10_000, null, 'P1']], 'Ränta');
    // Not the project's: another project, and no project at all.
    e7Post('2026-03-15', [[1510, 4_000_000], [3001, -4_000_000, null, 'P2']], 'Tak');
    e7Post('2026-03-16', [[4010, 900_000], [1930, -900_000]], 'Förbrukning');
}

/** An active budget for 2026 with lines on P1, on P2 and without a project. */
function e7Budget(?FiscalYear $year = null): BudgetVersion
{
    $year ??= test()->year;
    $version = app(CreateBudgetVersion::class)(test()->owner, test()->company, ['fiscal_year_id' => $year->id, 'name' => 'Budget', 'start' => CreateBudgetVersion::EMPTY]);
    $account = fn (int $number): int => (int) Account::query()->where('number', $number)->value('id');
    $month = fn (string $month): string => "{$year->start_date->format('Y')}-{$month}";

    app(SaveBudgetLines::class)(test()->owner, $version, [
        ['account_id' => $account(3001), 'cost_center_id' => null, 'project_id' => test()->project->id, 'amounts' => [$month('03') => -12_000_000]],
        ['account_id' => $account(4010), 'cost_center_id' => null, 'project_id' => test()->project->id, 'amounts' => [$month('03') => 1_500_000]],
        ['account_id' => $account(3001), 'cost_center_id' => null, 'project_id' => test()->other->id, 'amounts' => [$month('03') => -9_999_900]],
        ['account_id' => $account(4010), 'cost_center_id' => null, 'project_id' => null, 'amounts' => [$month('03') => 7_777_700]],
    ]);

    return $version->refresh();
}

it('shows revenue, costs by group, contribution and margin for the project only', function () {
    e7Project();

    $report = Livewire::test(ProjectResult::class, ['project' => $this->project])->assertOk()->viewData('report');
    $groups = collect($report['groups'])->keyBy('key');

    expect($groups->keys()->all())->toBe(['revenue', 'material', 'external', 'personnel', 'below'])
        ->and($groups['revenue']['total']['actual'])->toBe(10_000_000)
        ->and($groups['material']['rows'])->toHaveCount(1)
        ->and($groups['material']['total']['actual'])->toBe(-2_000_000)
        ->and($groups['external']['total']['actual'])->toBe(-500_000)
        ->and($groups['personnel']['total']['actual'])->toBe(-3_000_000)
        ->and($groups['below']['total']['actual'])->toBe(10_000)
        ->and($groups['personnel']['subtotal']['actual'])->toBe(4_500_000)
        ->and($report['actual'])->toBe(['revenue' => 10_000_000, 'costs' => 5_500_000, 'contribution' => 4_500_000, 'margin' => 4500, 'result' => 4_510_000]);
});

it('groups reverse charge and EU purchases as external costs, and financial income below the contribution', function () {
    expect(ProjectResults::groupOf(4010))->toBe('material')
        ->and(ProjectResults::groupOf(4425))->toBe('external')
        ->and(ProjectResults::groupOf(4535))->toBe('external')
        ->and(ProjectResults::groupOf(4610))->toBe('external')
        ->and(ProjectResults::groupOf(7010))->toBe('personnel')
        ->and(ProjectResults::groupOf(7832))->toBe('below')
        ->and(ProjectResults::groupOf(8310))->toBe('below');
});

it('shows the revenue of an invoice paid under kontantmetoden on the project', function () {
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kundbolaget AB', 'payment_terms_days' => 30]);
    $invoice = app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id, 'invoice_date' => '2026-03-10',
        'rows' => [['description' => 'Kakelsättning', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500, 'project' => 'P1']],
    ]));

    // Kontantmetoden: nothing on the project until the customer pays.
    expect(Livewire::test(ProjectResult::class, ['project' => $this->project])->viewData('report')['actual']['revenue'])->toBe(0);

    app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-04-10'), Money::ofOre(125_000));

    $report = Livewire::withQueryParams([])->test(ProjectResult::class, ['project' => $this->project])->viewData('report');

    expect($report['actual']['revenue'])->toBe(100_000)
        ->and(Livewire::test(ProjectList::class)->viewData('results')[$this->project->id]['actual']['revenue'])->toBe(100_000);
});

it('compares the whole project with its contract sum and estimated cost', function () {
    e7Project();

    $estimate = Livewire::test(ProjectResult::class, ['project' => $this->project])->viewData('estimate');

    expect($estimate)->toBe([
        'contract' => 20_000_000,
        'revenue_share' => 5000,
        'revenue_left' => 10_000_000,
        'estimated_cost' => 15_000_000,
        'cost_share' => 3660,
        'cost_left' => 9_510_000,
        'expected_contribution' => 5_000_000,
        'expected_margin' => 2500,
    ]);
});

it('reads every fiscal year for the whole project and one year with its months', function () {
    e7Project();
    $next = app(CreateNextFiscalYear::class)($this->owner, $this->company);
    e7Post('2027-02-01', [[1510, 5_000_000], [3001, -5_000_000, null, 'P1']], 'Faktura etapp 2');

    $life = Livewire::test(ProjectResult::class, ['project' => $this->project]);
    $year = Livewire::withQueryParams(['ar' => (string) $next->id])->test(ProjectResult::class, ['project' => $this->project]);

    expect($life->viewData('report')['actual']['revenue'])->toBe(15_000_000)
        ->and($life->viewData('estimate'))->not->toBeNull()
        ->and($year->viewData('report')['actual']['revenue'])->toBe(5_000_000)
        ->and($year->viewData('estimate'))->toBeNull()
        ->and($year->viewData('report')['months'])->toHaveCount(12)
        ->and($year->viewData('report')['monthly']['revenue']['2027-02'])->toBe(5_000_000);

    $first = Livewire::withQueryParams(['ar' => (string) $this->year->id])->test(ProjectResult::class, ['project' => $this->project])->viewData('report');

    expect($first['monthly']['revenue']['2026-03'])->toBe(10_000_000)
        ->and($first['monthly']['contribution']['2026-04'])->toBe(-3_500_000)
        ->and(array_sum($first['monthly']['contribution']))->toBe($first['actual']['contribution']);
});

it('compares with the lines on the project in the active budget, not a forecast or another project', function () {
    e7Project();
    e7Budget();

    $report = Livewire::test(ProjectResult::class, ['project' => $this->project])->viewData('report');
    $revenue = collect($report['groups'])->firstWhere('key', 'revenue');
    $material = collect($report['groups'])->firstWhere('key', 'material');

    expect($report['has_budget'])->toBeTrue()
        ->and($revenue['rows'][0])->toMatchArray(['number' => 3001, 'actual' => 10_000_000, 'budget' => 12_000_000, 'difference' => -2_000_000, 'percent' => -1667])
        ->and($material['rows'][0])->toMatchArray(['actual' => -2_000_000, 'budget' => -1_500_000, 'difference' => -500_000])
        ->and($report['budget']['contribution'])->toBe(10_500_000);

    // An inactive version of the same year is never read.
    app(CreateBudgetVersion::class)($this->owner, $this->company, ['fiscal_year_id' => $this->year->id, 'name' => 'Utkast', 'start' => CreateBudgetVersion::EMPTY]);

    expect(Livewire::test(ProjectResult::class, ['project' => $this->project])->viewData('report')['budget']['contribution'])->toBe(10_500_000);
});

it('shows a budget line without posted rows, and explains the empty drill-down', function () {
    e7Budget();

    Livewire::test(ProjectResult::class, ['project' => $this->project])
        ->call('drill', 3001)
        ->assertSet('konto', '3001')
        ->assertSee('Inga bokförda rader på kontot för perioden.');
});

it('drills down from an account to its posted rows, and closes again', function () {
    e7Project();
    e7Post('2026-06-01', [[4010, 300_000, null, 'P1'], [1930, -300_000]], 'Spackel');

    $page = Livewire::test(ProjectResult::class, ['project' => $this->project])->call('drill', 4010);
    $drill = $page->viewData('drill');

    expect($page->get('konto'))->toBe('4010')
        ->and(array_column($drill['rows'], 'date'))->toBe(['2026-03-12', '2026-06-01'])
        ->and(array_column($drill['rows'], 'text'))->toBe(['Kakel', 'Spackel'])
        ->and(array_column($drill['rows'], 'amount'))->toBe([-2_000_000, -300_000])
        ->and(array_column($drill['rows'], 'linked'))->toBe([true, true])
        ->and($drill['more'])->toBeFalse();

    $page->assertSee(route('accounting.vouchers.show', $drill['rows'][0]['voucher_id']), false)
        ->call('drill', 4010)
        ->assertSet('konto', '')
        ->call('drill', 4010)
        ->call('closeDrill')
        ->assertSet('konto', '');
});

it('links the drill-down to the account analysis of the chosen year', function () {
    e7Project();

    Livewire::withQueryParams(['ar' => (string) $this->year->id, 'konto' => '3001'])
        ->test(ProjectResult::class, ['project' => $this->project])
        ->assertSee(route('accounting.reports', ['rapport' => 'huvudbok', 'ar' => (string) $this->year->id, 'projekt' => 'P1', 'konto' => '3001']));
});

it('caps the drill-down and says where the rest is', function () {
    $rows = [];

    for ($i = 0; $i < ProjectResults::DRILL_LIMIT + 1; $i++) {
        $rows[] = [4010, 100, null, 'P1'];
    }

    e7Post('2026-03-01', [...$rows, [1930, -100 * (ProjectResults::DRILL_LIMIT + 1)]]);

    $drill = Livewire::test(ProjectResult::class, ['project' => $this->project])->call('drill', 4010)->viewData('drill');

    expect($drill['rows'])->toHaveCount(ProjectResults::DRILL_LIMIT)
        ->and($drill['more'])->toBeTrue();
});

it('lists every project with its result and budgeted contribution', function () {
    e7Project();
    e7Budget();

    $page = Livewire::test(ProjectList::class)->assertOk()->assertSee('Ombyggnad kök')->assertSee('Nytt tak');
    $results = $page->viewData('results');

    expect($results[$this->project->id]['actual'])->toBe(['revenue' => 10_000_000, 'costs' => 5_500_000, 'contribution' => 4_500_000, 'margin' => 4500, 'result' => 4_510_000])
        ->and($results[$this->project->id]['budget']['contribution'])->toBe(10_500_000)
        ->and($results[$this->other->id]['actual']['contribution'])->toBe(4_000_000)
        ->and($results[$this->other->id]['budget']['revenue'])->toBe(9_999_900);

    $planned = Livewire::withQueryParams(['status' => 'PLANNED'])->test(ProjectList::class);

    expect($planned->viewData('projects'))->toHaveCount(0);
});

it('agrees with the project filter of the income statement', function () {
    e7Project();

    $report = Livewire::withQueryParams(['ar' => (string) $this->year->id])->test(ProjectResult::class, ['project' => $this->project])->viewData('report');
    $statement = Livewire::test(Reports::class, ['rapport' => 'resultat', 'projekt' => 'P1'])->viewData('data');

    expect($report['actual']['result'])->toBe($statement['result']);
});

it('opens for every role with projects:view and refuses the others', function (string $role, int $status) {
    $member = Bookkeeping::member($this->company, $role);

    $this->actingAs($member)->get(route('projects.index'))->assertStatus($status);
    $this->actingAs($member)->get(route('projects.show', $this->project))->assertStatus($status);
})->with([
    'bookkeeper' => ['bookkeeper', 200],
    'accountant' => ['accountant', 200],
    'auditor' => ['auditor', 200],
    'approver' => ['approver', 403],
]);

it('reaches the project results from the menu and from the register', function () {
    $sections = app(Navigation::class)->for($this->owner, $this->company, 'projects.show');
    $links = collect($sections)->flatMap(fn ($section) => array_map(fn ($link) => $link->route, $section->children))->all();

    expect($links)->toContain('projects.index');

    $this->get(route('accounting.projects'))->assertOk()->assertSee(route('projects.show', $this->project), false);
});

it('writes nothing: no voucher, row or audit entry', function () {
    e7Project();
    e7Budget();
    $before = [Voucher::query()->count(), VoucherRow::query()->count(), AuditLog::query()->count()];

    Livewire::test(ProjectResult::class, ['project' => $this->project])->call('drill', 3001);
    Livewire::test(ProjectList::class);

    expect([Voucher::query()->count(), VoucherRow::query()->count(), AuditLog::query()->count()])->toBe($before);
});

/** @return int queries run by $callback */
function e7Queries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** $months of postings on P1 and P2, on $accounts different accounts, and a budget. */
function e7History(int $months, int $accounts): void
{
    $numbers = array_slice([4010, 6550, 7010, 7210, 7832, 6110], 0, $accounts);

    for ($month = 1; $month <= $months; $month++) {
        $date = sprintf('2026-%02d-05', $month);
        e7Post($date, [[1510, 1_000_000], [3001, -1_000_000, '10', 'P1', "Faktura {$month}"]]);

        foreach ($numbers as $number) {
            e7Post($date, [[$number, 10_000, '20', $month % 2 === 0 ? 'P1' : 'P2'], [1930, -10_000]]);
        }
    }
}

it('renders both pages and the drill-down in a fixed number of queries', function () {
    e7History(1, 1);
    // The first render loads what the request caches (the member's role); measure after it.
    Livewire::test(ProjectList::class);

    $listShort = e7Queries(fn () => Livewire::test(ProjectList::class));
    $pageShort = e7Queries(fn () => Livewire::withQueryParams([])->test(ProjectResult::class, ['project' => $this->project]));
    $drillShort = e7Queries(fn () => Livewire::withQueryParams(['konto' => '3001'])->test(ProjectResult::class, ['project' => $this->project]));

    e7History(12, 6);
    e7Budget();
    $next = app(CreateNextFiscalYear::class)($this->owner, $this->company);
    e7Budget($next);
    e7Post('2027-03-01', [[1510, 50_000], [3001, -50_000, null, 'P1']]);

    for ($i = 3; $i <= 12; $i++) {
        app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::Project, ['code' => "P{$i}", 'name' => "Projekt {$i}"]);
    }

    expect(e7Queries(fn () => Livewire::test(ProjectList::class)))->toBe($listShort)
        ->and(e7Queries(fn () => Livewire::withQueryParams([])->test(ProjectResult::class, ['project' => $this->project])))->toBe($pageShort)
        ->and(e7Queries(fn () => Livewire::withQueryParams(['konto' => '3001'])->test(ProjectResult::class, ['project' => $this->project])))->toBe($drillShort);
});

it('reads the ledger of every year in one statement', function () {
    e7Project();
    app(CreateNextFiscalYear::class)($this->owner, $this->company);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ProjectResult::class, ['project' => $this->project]);
    $ledger = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'from "voucher_rows"'));
    DB::disableQueryLog();

    expect($ledger)->toHaveCount(1)
        ->and($ledger->first())->toContain('union all');
});

it('answers 404 for a project id that does not exist', function () {
    $this->get(route('projects.show', ['project' => Project::query()->max('id') + 100]))->assertNotFound();
});
