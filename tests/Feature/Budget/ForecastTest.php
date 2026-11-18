<?php

declare(strict_types=1);

use App\Actions\Accounting\BookVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\CreateForecast;
use App\Actions\Budget\ManageBudgetVersion;
use App\Actions\Budget\SaveBudgetLines;
use App\Actions\Budget\SaveForecast;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\ForecastMethod;
use App\Domain\Budget\RollingTwelveMonths;
use App\Livewire\Accounting\BudgetsPage;
use App\Livewire\Accounting\ForecastEditor;
use App\Livewire\Accounting\RollingForecast;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Rolling forecast (ADR-081 gap 2 scope 5, package E5). Today is 2026-04-10,
 * so the cut-offs of 2026 are December 2025 to March 2026.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
});

function e5Account(int $number): int
{
    return (int) Account::query()->where('number', $number)->value('id');
}

/** The active budget of 2026: 10 000 kr revenue on 3001 every month, and 5010 at 1 000 kr. */
function e5Budget(): BudgetVersion
{
    $version = app(CreateBudgetVersion::class)(test()->owner, test()->company, [
        'fiscal_year_id' => test()->year->id, 'name' => 'Budget 2026', 'start' => CreateBudgetVersion::EMPTY,
    ]);
    $months = [];

    foreach (range(1, 12) as $month) {
        $months[sprintf('2026-%02d', $month)] = true;
    }

    return app(SaveBudgetLines::class)(test()->owner, $version, [
        ['account_id' => e5Account(3001), 'cost_center_id' => null, 'project_id' => null, 'amounts' => array_map(fn () => -10_000_00, $months)],
        ['account_id' => e5Account(5010), 'cost_center_id' => null, 'project_id' => null, 'amounts' => array_map(fn () => 1_000_00, $months)],
    ]);
}

/** @param array<string, mixed> $input */
function e5Forecast(array $input = []): BudgetVersion
{
    return app(CreateForecast::class)(test()->owner, test()->company, [
        'fiscal_year_id' => test()->year->id,
        'name' => 'Prognos mars',
        'cutoff' => '2026-03',
        'revenue_method' => ForecastMethod::Budget->value,
        'cost_method' => ForecastMethod::Average->value,
        'average_months' => 3,
        'growth' => '',
        ...$input,
    ]);
}

/** A cost on 5010 (debit), paid from the bank. */
function e5Cost(int $ore, string $date): void
{
    Bookkeeping::post(test()->owner, test()->company, ore: $ore, debit: 5010, credit: 1930, date: $date);
}

/** @return array<string, int> 'Y-m' => ledger öre of one row of the version */
function e5Row(BudgetVersion $version, int $number, ?int $costCenterId = null): array
{
    return BudgetLine::query()
        ->where('budget_version_id', $version->id)
        ->where('account_id', e5Account($number))
        ->when($costCenterId === null, fn ($query) => $query->whereNull('cost_center_id'), fn ($query) => $query->where('cost_center_id', $costCenterId))
        ->orderBy('period')
        ->get()
        ->mapWithKeys(fn (BudgetLine $line): array => [$line->period->format('Y-m') => $line->amount_ore->ore])
        ->all();
}

function e5PreviousYear(): FiscalYear
{
    return FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
}

it('uses owner decision 7 by default: the outcome to the cut-off, the budget for revenue and the 3-month average for costs', function () {
    e5Budget();
    Bookkeeping::post($this->owner, $this->company, ore: 12_000_00, date: '2026-01-15');
    Bookkeeping::post($this->owner, $this->company, ore: 8_000_00, date: '2026-03-15');
    e5Cost(300_00, '2026-01-20');
    e5Cost(600_00, '2026-02-20');
    e5Cost(901_00, '2026-03-20');

    $forecast = e5Forecast();

    expect($forecast->kind)->toBe(BudgetKind::Forecast)
        ->and($forecast->forecast_cutoff->toDateString())->toBe('2026-03-31')
        ->and($forecast->is_active)->toBeTrue()
        ->and($forecast->method)->toMatchArray(['revenue' => 'BUDGET', 'costs' => 'AVERAGE', 'average_months' => 3]);

    $revenue = e5Row($forecast, 3001);
    $costs = e5Row($forecast, 5010);

    // The outcome exactly; February had no sales. After the cut-off: the budget, and (300 + 600 + 901) / 3 = 600,33 → 600 kr.
    expect($revenue['2026-01'])->toBe(-12_000_00)
        ->and($revenue)->not->toHaveKey('2026-02')
        ->and($revenue['2026-03'])->toBe(-8_000_00)
        ->and($revenue['2026-04'])->toBe(-10_000_00)
        ->and($revenue['2026-12'])->toBe(-10_000_00)
        ->and($costs['2026-03'])->toBe(901_00)
        ->and($costs['2026-04'])->toBe(600_00)
        ->and($costs['2026-12'])->toBe(600_00)
        ->and(BudgetLine::query()->where('budget_version_id', $forecast->id)->where('is_override', true)->count())->toBe(0);
});

it('takes the average across the fiscal year boundary, and over the months there are when the books are younger', function () {
    e5Cost(900_00, '2026-01-10');

    $young = e5Forecast(['cutoff' => '2026-01', 'revenue_method' => 'AVERAGE', 'name' => 'Ung']);

    expect(e5Row($young, 5010)['2026-02'])->toBe(900_00);

    e5PreviousYear();
    e5Cost(300_00, '2025-11-10');
    e5Cost(600_00, '2025-12-10');

    $boundary = e5Forecast(['cutoff' => '2026-01', 'revenue_method' => 'AVERAGE', 'name' => 'Över årsskiftet']);

    expect(e5Row($boundary, 5010)['2026-02'])->toBe(600_00)
        ->and(e5Row($boundary, 5010))->not->toHaveKey('2025-12');
});

it('takes the same month last year with a growth percentage', function () {
    e5PreviousYear();
    e5Cost(1_000_00, '2025-05-10');
    e5Cost(2_000_00, '2025-09-10');

    $forecast = e5Forecast(['revenue_method' => 'LAST_YEAR', 'cost_method' => 'LAST_YEAR', 'growth' => '10']);

    expect(e5Row($forecast, 5010))->toBe(['2026-05' => 1_100_00, '2026-09' => 2_200_00])
        ->and($forecast->method['growth_bp'])->toBe(1000);
});

it('counts every posted amount on exactly one row, the most specific one', function () {
    $shop = CostCenter::create(['code' => 'K1', 'name' => 'Butik']);
    $other = CostCenter::create(['code' => 'K2', 'name' => 'Lager']);
    $budget = app(CreateBudgetVersion::class)($this->owner, $this->company, ['fiscal_year_id' => $this->year->id, 'name' => 'B', 'start' => 'empty']);
    app(SaveBudgetLines::class)($this->owner, $budget, [
        ['account_id' => e5Account(5010), 'cost_center_id' => $shop->id, 'project_id' => null, 'amounts' => ['2026-06' => 700_00]],
    ]);

    app(PostVoucher::class)($this->owner, app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-02-10', 'description' => 'Hyra', 'series' => 'A',
        'rows' => [
            ['account' => 5010, 'debit_ore' => 100_00, 'cost_center' => 'K1'],
            ['account' => 5010, 'debit_ore' => 200_00, 'cost_center' => 'K2'],
            ['account' => 5010, 'debit_ore' => 400_00],
            ['account' => 1930, 'credit_ore' => 700_00],
        ],
    ]));

    $forecast = e5Forecast(['cost_method' => 'BUDGET']);

    // K1 has a budget row of its own; K2 and the amount without a cost centre go to the account's own row.
    expect(e5Row($forecast, 5010, $shop->id))->toBe(['2026-02' => 100_00, '2026-06' => 700_00])
        ->and(e5Row($forecast, 5010))->toBe(['2026-02' => 600_00])
        ->and(BudgetLine::query()->where('budget_version_id', $forecast->id)->where('cost_center_id', $other->id)->exists())->toBeFalse()
        ->and((int) BudgetLine::query()->where('budget_version_id', $forecast->id)->whereDate('period', '2026-02-01')->sum('amount_ore'))->toBe(700_00);
});

it('keeps months typed by hand when it computes again, and counts a voucher posted late', function () {
    e5Budget();
    e5Cost(600_00, '2026-03-20');
    $forecast = e5Forecast();
    $key = e5Account(5010).'-0-0';

    $saved = app(SaveForecast::class)($this->owner, $forecast, [
        'revenue_method' => 'BUDGET', 'cost_method' => 'AVERAGE', 'average_months' => 3, 'growth' => '',
        'overrides' => [$key => ['2026-06' => 5_000_00, '2026-07' => 0]],
    ]);

    expect(e5Row($saved, 5010)['2026-06'])->toBe(5_000_00)
        ->and(e5Row($saved, 5010)['2026-07'])->toBe(0)
        ->and(e5Row($saved, 5010)['2026-05'])->toBe(200_00)
        ->and(BudgetLine::query()->where('budget_version_id', $forecast->id)->where('is_override', true)->count())->toBe(2);

    // A voucher for February posted after the forecast was made.
    e5Cost(900_00, '2026-02-20');

    app(SaveForecast::class)($this->owner, $saved->refresh(), [
        'revenue_method' => 'BUDGET', 'cost_method' => 'AVERAGE', 'average_months' => 3, 'growth' => '',
        'overrides' => [$key => ['2026-06' => 5_000_00]],
    ]);

    $row = e5Row($saved, 5010);

    expect($row['2026-02'])->toBe(900_00)
        ->and($row['2026-05'])->toBe(500_00)
        ->and($row['2026-06'])->toBe(5_000_00)
        ->and($row['2026-07'])->toBe(500_00);
});

it('moves the cut-off and drops the months typed by hand that are now outcome', function () {
    e5Cost(300_00, '2026-01-20');
    $forecast = e5Forecast(['cutoff' => '2026-01', 'revenue_method' => 'AVERAGE']);
    $key = e5Account(5010).'-0-0';

    app(SaveForecast::class)($this->owner, $forecast, [
        'revenue_method' => 'AVERAGE', 'cost_method' => 'AVERAGE', 'average_months' => 1, 'growth' => '',
        'overrides' => [$key => ['2026-02' => 9_000_00]],
    ]);

    // February is now outcome: the amount typed for it is skipped, not refused.
    app(SaveForecast::class)($this->owner, $forecast->refresh(), [
        'cutoff' => '2026-03', 'revenue_method' => 'AVERAGE', 'cost_method' => 'AVERAGE', 'average_months' => 1, 'growth' => '',
        'overrides' => [$key => ['2026-02' => 9_000_00]],
    ]);

    expect($forecast->refresh()->forecast_cutoff->toDateString())->toBe('2026-03-31')
        ->and(e5Row($forecast, 5010))->toBe(['2026-01' => 300_00])
        ->and(BudgetLine::query()->where('budget_version_id', $forecast->id)->where('is_override', true)->count())->toBe(0);
});

it('lets a row have a method of its own', function () {
    e5Budget();
    e5Cost(600_00, '2026-03-20');
    $forecast = e5Forecast();

    app(SaveForecast::class)($this->owner, $forecast, [
        'revenue_method' => 'BUDGET', 'cost_method' => 'AVERAGE', 'average_months' => 3, 'growth' => '',
        'row_methods' => [e5Account(5010).'-0-0' => 'BUDGET'],
    ]);

    expect(e5Row($forecast, 5010)['2026-04'])->toBe(1_000_00)
        ->and($forecast->refresh()->method['rows'])->toBe([e5Account(5010).'-0-0' => 'BUDGET']);
});

it('refuses a cut-off that has not ended or is outside the year, and the budget method without a budget', function () {
    expect(fn () => e5Forecast(['cutoff' => '2026-04', 'revenue_method' => 'AVERAGE']))->toThrow(ValidationException::class)
        ->and(fn () => e5Forecast(['cutoff' => '2025-10', 'revenue_method' => 'AVERAGE']))->toThrow(ValidationException::class)
        ->and(fn () => e5Forecast())->toThrow(ValidationException::class, 'ingen budget')
        ->and(fn () => e5Forecast(['revenue_method' => 'GUESS']))->toThrow(ValidationException::class)
        ->and(fn () => e5Forecast(['average_months' => 13, 'revenue_method' => 'AVERAGE']))->toThrow(ValidationException::class);

    // The month before the year starts is a cut-off: everything forecast.
    expect(e5Forecast(['cutoff' => '2025-12', 'revenue_method' => 'AVERAGE'])->forecast_cutoff->toDateString())->toBe('2025-12-31');
});

it('skips months typed over the outcome, and refuses other changes than its own', function () {
    $forecast = e5Forecast(['revenue_method' => 'AVERAGE']);
    $key = e5Account(5010).'-0-0';
    $input = ['revenue_method' => 'AVERAGE', 'cost_method' => 'AVERAGE', 'average_months' => 3, 'growth' => ''];

    app(SaveForecast::class)($this->owner, $forecast, [...$input, 'overrides' => [$key => ['2026-02' => 1_00]]]);

    expect(BudgetLine::query()->where('budget_version_id', $forecast->id)->count())->toBe(0)
        ->and(fn () => app(SaveForecast::class)($this->owner, $forecast, [...$input, 'overrides' => [$key => ['2027-02' => 1_00]]]))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveForecast::class)($this->owner, $forecast, [...$input, 'overrides' => [$key => ['2026-05' => '100']]]))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveForecast::class)($this->owner, $forecast, [...$input, 'extra_rows' => [e5Account(1930).'-0-0']]))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveBudgetLines::class)($this->owner, $forecast, []))->toThrow(ValidationException::class);

    $budget = app(CreateBudgetVersion::class)($this->owner, $this->company, ['fiscal_year_id' => $this->year->id, 'name' => 'B', 'start' => 'empty']);

    expect(fn () => app(SaveForecast::class)($this->owner, $budget, $input))->toThrow(ValidationException::class);
});

it('refuses forecasts to a member without budgets:manage and a locked forecast', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $input = ['fiscal_year_id' => $this->year->id, 'name' => 'X', 'cutoff' => '2026-03', 'revenue_method' => 'AVERAGE', 'cost_method' => 'AVERAGE', 'average_months' => 3];

    expect(fn () => app(CreateForecast::class)($bookkeeper, $this->company, $input))->toThrow(AuthorizationException::class);

    $forecast = e5Forecast(['revenue_method' => 'AVERAGE']);

    expect(fn () => app(SaveForecast::class)($bookkeeper, $forecast, $input))->toThrow(AuthorizationException::class);

    app(ManageBudgetVersion::class)->lock($this->owner, $forecast);

    expect(fn () => app(SaveForecast::class)($this->owner, $forecast->refresh(), $input))->toThrow(AuthorizationException::class);
});

it('makes a new forecast from an earlier one, keeping its own methods and the months typed after the new cut-off', function () {
    e5Cost(300_00, '2026-01-20');
    $first = e5Forecast(['cutoff' => '2026-01', 'revenue_method' => 'AVERAGE']);
    $key = e5Account(5010).'-0-0';

    app(SaveForecast::class)($this->owner, $first, [
        'revenue_method' => 'AVERAGE', 'cost_method' => 'AVERAGE', 'average_months' => 3, 'growth' => '',
        'row_methods' => [$key => 'LAST_YEAR'],
        'overrides' => [$key => ['2026-02' => 1_000_00, '2026-08' => 4_000_00]],
    ]);

    $second = e5Forecast(['cutoff' => '2026-03', 'revenue_method' => 'AVERAGE', 'source_version_id' => $first->id, 'name' => 'Prognos mars']);

    expect($second->source_version_id)->toBe($first->id)
        ->and($second->is_active)->toBeFalse()
        ->and($second->method['rows'])->toBe([$key => 'LAST_YEAR'])
        ->and(e5Row($second, 5010))->toBe(['2026-01' => 300_00, '2026-08' => 4_000_00])
        ->and(BudgetLine::query()->where('budget_version_id', $second->id)->where('is_override', true)->count())->toBe(1);
});

it('keeps a forecast with a cut-off and a budget without one in the database', function () {
    $budget = app(CreateBudgetVersion::class)($this->owner, $this->company, ['fiscal_year_id' => $this->year->id, 'name' => 'B', 'start' => 'empty']);

    expect(fn () => DB::transaction(fn () => DB::table('budget_versions')->where('id', $budget->id)->update(['forecast_cutoff' => '2026-03-31'])))->toThrow(QueryException::class);

    $forecast = e5Forecast(['revenue_method' => 'AVERAGE']);

    expect(fn () => DB::transaction(fn () => DB::table('budget_versions')->where('id', $forecast->id)->update(['forecast_cutoff' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('budget_versions')->where('id', $forecast->id)->update(['forecast_cutoff' => '2026-03-15'])))->toThrow(QueryException::class);
});

it('reads twelve months across the fiscal year boundary from two versions', function () {
    e5Budget();
    Bookkeeping::post($this->owner, $this->company, ore: 7_000_00, date: '2026-03-15');
    e5Forecast();

    $next = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    $nextBudget = app(CreateBudgetVersion::class)($this->owner, $this->company, ['fiscal_year_id' => $next->id, 'name' => 'Budget 2027', 'start' => 'empty']);
    app(SaveBudgetLines::class)($this->owner, $nextBudget, [
        ['account_id' => e5Account(3001), 'cost_center_id' => null, 'project_id' => null, 'amounts' => ['2027-01' => -20_000_00, '2027-03' => -30_000_00]],
    ]);

    $report = RollingTwelveMonths::build('2026-03');
    $revenue = $report['groups'][0]['rows'][0];

    expect($report['months'][0])->toBe('2026-03')
        ->and($report['months'][11])->toBe('2027-02')
        ->and($report['sources']['2026-03'])->toMatchArray(['kind' => BudgetKind::Forecast, 'actual' => true])
        ->and($report['sources']['2026-04'])->toMatchArray(['kind' => BudgetKind::Forecast, 'actual' => false])
        ->and($report['sources']['2027-01'])->toMatchArray(['kind' => BudgetKind::Budget, 'version_id' => $nextBudget->id])
        ->and($revenue['number'])->toBe(3001)
        ->and($revenue['amounts']['2026-03'])->toBe(7_000_00)
        ->and($revenue['amounts']['2026-04'])->toBe(10_000_00)
        ->and($revenue['amounts']['2027-01'])->toBe(20_000_00)
        ->and($revenue['amounts']['2027-02'])->toBe(0)
        ->and($revenue['total'])->toBe(7_000_00 + 9 * 10_000_00 + 20_000_00);

    // Without a version, a month is empty and named.
    $later = RollingTwelveMonths::build('2027-06');

    expect($later['sources']['2028-01'])->toMatchArray(['version_id' => null, 'kind' => null]);
});

it('never writes a voucher, and audits without names', function () {
    e5Budget();
    e5Cost(600_00, '2026-03-20');
    $vouchers = Voucher::query()->count();
    $rows = VoucherRow::query()->count();
    $lastAudit = (int) AuditLog::query()->max('id');

    $forecast = e5Forecast(['name' => 'Hemlig plan']);
    app(SaveForecast::class)($this->owner, $forecast, ['revenue_method' => 'BUDGET', 'cost_method' => 'AVERAGE', 'average_months' => 2, 'growth' => '5']);
    RollingTwelveMonths::build('2026-04');
    app(ManageBudgetVersion::class)->lock($this->owner, $forecast->refresh());
    app(ManageBudgetVersion::class)->delete($this->owner, $forecast->refresh());

    $logs = AuditLog::query()->where('id', '>', $lastAudit)->get();

    expect(Voucher::query()->count())->toBe($vouchers)
        ->and(VoucherRow::query()->count())->toBe($rows)
        ->and($logs->pluck('action')->unique()->values()->all())->toBe(['budget.forecast_created', 'budget.forecast_saved', 'budget.locked', 'budget.deleted'])
        ->and($logs->firstWhere('action', 'budget.forecast_created')->payload)->toMatchArray(['cutoff' => '2026-03', 'revenue_method' => 'BUDGET', 'cost_method' => 'AVERAGE'])
        ->and(json_encode($logs->pluck('payload')->all()))->not->toContain('Hemlig plan');
});

it('creates a forecast from the list with the default methods and a toast', function () {
    e5Budget();

    Livewire::test(BudgetsPage::class)
        ->call('createForecast')
        ->assertSet('forecastForm.cutoff', '2026-03')
        ->assertSet('forecastForm.revenue_method', 'BUDGET')
        ->assertSet('forecastForm.cost_method', 'AVERAGE')
        ->assertSee('Utfall till och med')
        ->call('saveForecast')
        ->assertHasNoErrors()
        ->assertRedirect();

    $forecast = BudgetVersion::query()->where('kind', 'FORECAST')->firstOrFail();

    expect($forecast->name)->toBe('Prognos mars 2026');

    Livewire::test(BudgetsPage::class)
        ->assertSee('Prognos, utfall t.o.m.')
        ->assertSee('Används i Rullande 12 månader')
        ->call('delete', $forecast->id)
        ->assertDispatched('toast');
});

it('offers the average for revenue when the year has no budget', function () {
    Livewire::test(BudgetsPage::class)
        ->call('createForecast')
        ->assertSet('forecastForm.revenue_method', 'AVERAGE')
        ->assertSee('intäkterna räknas med snittet');
});

it('types over a month, follows the method again and adds a row in the grid', function () {
    e5Budget();
    e5Cost(600_00, '2026-03-20');
    $forecast = e5Forecast();
    $key = e5Account(5010).'-0-0';
    $april = 3;

    $component = Livewire::test(ForecastEditor::class, ['version' => $forecast])
        ->assertSee('Utfall')
        ->assertSet("computed.{$key}.{$april}", '200')
        ->set("cells.{$key}.{$april}", '1 500')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertSet("cells.{$key}.{$april}", '1500');

    expect(e5Row($forecast, 5010)['2026-04'])->toBe(1_500_00)
        ->and(BudgetLine::query()->where('budget_version_id', $forecast->id)->where('is_override', true)->count())->toBe(1);

    $component->call('resetRow', $key)->call('save');

    expect(BudgetLine::query()->where('budget_version_id', $forecast->id)->where('is_override', true)->count())->toBe(0);

    $component->set('newAccount', '6110')->call('addRow')->assertHasNoErrors();
    $added = e5Account(6110).'-0-0';
    $component->set("cells.{$added}.5", '250')->call('save')->assertHasNoErrors();

    expect(e5Row($forecast, 6110))->toBe(['2026-06' => 250_00]);

    $component->set("cells.{$key}.5", 'tio')->call('save')->assertHasErrors("cells.{$key}.5");
});

it('shows a locked forecast read-only and opens the pages for those who may see them', function () {
    $forecast = e5Forecast(['revenue_method' => 'AVERAGE']);

    $this->get(route('accounting.budgets.show', $forecast))->assertOk()->assertSee('Prognos per konto och månad');
    $this->get(route('accounting.budgets.rolling'))->assertOk()->assertSee('Rullande 12 månader');
    $this->get(route('accounting.budgets.csv', $forecast))->assertOk()->assertDownload('prognos_prognos-mars_2026.csv');

    app(ManageBudgetVersion::class)->lock($this->owner, $forecast);

    Livewire::test(ForecastEditor::class, ['version' => $forecast->refresh()])
        ->assertSee('Prognosen är låst')
        ->call('save')
        ->assertForbidden();

    $this->actingAs(Bookkeeping::member($this->company, 'auditor'));
    $this->get(route('accounting.budgets.show', $forecast))->assertOk();
    $this->get(route('accounting.budgets.rolling'))->assertOk();

    $this->actingAs(Bookkeeping::member($this->company, 'approver'));
    $this->get(route('accounting.budgets.show', $forecast))->assertForbidden();
    $this->get(route('accounting.budgets.rolling'))->assertForbidden();
});

it('refuses the forecast grid for a budget', function () {
    $budget = app(CreateBudgetVersion::class)($this->owner, $this->company, ['fiscal_year_id' => $this->year->id, 'name' => 'B', 'start' => 'empty']);

    Livewire::test(ForecastEditor::class, ['version' => $budget])->assertNotFound();
});

/** @return int queries run by $callback */
function e5Queries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** A ledger of $months months with $costCenters cost centres, a budget, and a forecast made from it. */
function e5History(int $months, int $costCenters): BudgetVersion
{
    for ($i = 1; $i <= $costCenters; $i++) {
        CostCenter::create(['code' => "F{$months}-{$i}", 'name' => "Ställe {$i}"]);
    }

    for ($month = 1; $month <= $months; $month++) {
        $date = sprintf('2025-%02d-10', $month);
        Bookkeeping::post(test()->owner, test()->company, ore: 1_000_00 * $month, date: $date);

        for ($i = 1; $i <= $costCenters; $i++) {
            app(PostVoucher::class)(test()->owner, app(SaveDraftVoucher::class)(test()->owner, test()->company, [
                'voucher_date' => $date, 'description' => 'Kostnad', 'series' => 'A',
                'rows' => [['account' => 5010, 'debit_ore' => 300_00, 'cost_center' => "F{$months}-{$i}"], ['account' => 1930, 'credit_ore' => 300_00]],
            ]));
        }
    }

    e5Cost(100_00, '2026-02-10');

    return e5Forecast(['name' => "Mätning {$months}", 'revenue_method' => 'LAST_YEAR']);
}

it('computes and renders forecasts in a fixed number of queries', function () {
    e5PreviousYear();
    $short = e5History(1, 1);
    // The first render loads what the request caches (the member's role); measure after it.
    Livewire::test(BudgetsPage::class);

    $editorShort = e5Queries(fn () => Livewire::test(ForecastEditor::class, ['version' => $short]));
    $rollingShort = e5Queries(fn () => Livewire::withQueryParams(['fran' => '2026-03'])->test(RollingForecast::class));
    $saveShort = e5Queries(fn () => app(SaveForecast::class)(test()->owner, $short->refresh(), ['revenue_method' => 'LAST_YEAR', 'cost_method' => 'AVERAGE', 'average_months' => 3, 'growth' => '']));

    $long = e5History(12, 8);

    expect(BudgetLine::query()->where('budget_version_id', $long->id)->count())->toBeGreaterThan(BudgetLine::query()->where('budget_version_id', $short->id)->count())
        ->and(e5Queries(fn () => Livewire::test(ForecastEditor::class, ['version' => $long])))->toBe($editorShort)
        ->and(e5Queries(fn () => Livewire::withQueryParams(['fran' => '2026-03'])->test(RollingForecast::class)))->toBe($rollingShort)
        ->and(e5Queries(fn () => app(SaveForecast::class)(test()->owner, $long->refresh(), ['revenue_method' => 'LAST_YEAR', 'cost_method' => 'AVERAGE', 'average_months' => 3, 'growth' => ''])))->toBe($saveShort);
});

it('leaves the year-end closing entries out of the average and last year, but keeps depreciation', function () {
    $previous = e5PreviousYear();
    $book = app(BookVoucher::class);
    $book($this->owner, $this->company, CarbonImmutable::parse('2025-12-31'), 'Årets skatt', 'A', [8910 => 300_000_00, 1930 => -300_000_00], VoucherSource::Closing, $previous);
    $book($this->owner, $this->company, CarbonImmutable::parse('2025-12-31'), 'Avskrivning', 'A', [7832 => 3_000_00, 1930 => -3_000_00], VoucherSource::Closing, Account::query()->where('number', 7832)->firstOrFail());
    Bookkeeping::post($this->owner, $this->company, ore: 3_000_00, debit: 7832, credit: 1930, date: '2026-01-31');
    Bookkeeping::post($this->owner, $this->company, ore: 3_000_00, debit: 7832, credit: 1930, date: '2026-02-28');

    $average = e5Forecast(['cutoff' => '2026-02', 'revenue_method' => 'AVERAGE', 'name' => 'Snitt']);
    $lastYear = e5Forecast(['cutoff' => '2026-02', 'revenue_method' => 'AVERAGE', 'cost_method' => 'LAST_YEAR', 'name' => 'Fjol']);

    expect(e5Row($average, 8910))->toBe([])
        ->and(e5Row($average, 7832)['2026-03'])->toBe(3_000_00)
        ->and(e5Row($lastYear, 8910))->toBe([])
        ->and(e5Row($lastYear, 7832))->toBe(['2026-01' => 3_000_00, '2026-02' => 3_000_00, '2026-12' => 3_000_00]);
});

it('keeps a row with its own method through two saves in a row, also when the method gives nothing', function () {
    $forecast = e5Forecast(['revenue_method' => 'AVERAGE']);
    $key = e5Account(6110).'-0-0';

    $component = Livewire::test(ForecastEditor::class, ['version' => $forecast])
        ->set('newAccount', '6110')
        ->call('addRow')
        ->set("rowMethods.{$key}", 'LAST_YEAR')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet("rowMethods.{$key}", 'LAST_YEAR')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet("rowMethods.{$key}", 'LAST_YEAR');

    expect($forecast->refresh()->method['rows'])->toBe([$key => 'LAST_YEAR'])
        ->and(collect($component->get('rows'))->pluck('key')->all())->toContain($key);
});

it('starts the cut-off at the locked period and warns about a month that is not locked', function () {
    Livewire::test(BudgetsPage::class)
        ->call('createForecast')
        ->assertSet('forecastForm.cutoff', '2026-03')
        ->assertSee('Brytmånaden är inte låst');

    $this->company->forceFill(['locked_until_date' => '2026-02-28'])->save();
    $this->owner->refresh();

    Livewire::test(BudgetsPage::class)
        ->call('createForecast')
        ->assertSet('forecastForm.cutoff', '2026-02')
        ->assertDontSee('Brytmånaden är inte låst');
});

it('says when there is no previous year and how many months the average covers', function () {
    e5Cost(300_00, '2026-01-20');

    Livewire::test(BudgetsPage::class)
        ->call('createForecast')
        ->assertSee('Det finns inget föregående räkenskapsår, så Samma månad i fjol ger 0.')
        ->assertSee('Snittet räknas på 3 månader.');

    $forecast = e5Forecast(['cutoff' => '2026-01', 'revenue_method' => 'AVERAGE']);

    Livewire::test(ForecastEditor::class, ['version' => $forecast])
        ->assertSee('Det finns inget föregående räkenskapsår')
        ->assertSee('Snittet räknas på en månad.');

    e5PreviousYear();

    Livewire::test(ForecastEditor::class, ['version' => $forecast])
        ->assertDontSee('Det finns inget föregående räkenskapsår')
        ->assertSee('Snittet räknas på 3 månader.');
});

it('refuses to delete a locked forecast of a closed year with a message of its own', function () {
    $forecast = e5Forecast(['revenue_method' => 'AVERAGE']);
    app(ManageBudgetVersion::class)->lock($this->owner, $forecast);
    DB::table('fiscal_years')->where('id', $this->year->id)->update(['status' => 'CLOSED']);

    expect(fn () => app(ManageBudgetVersion::class)->delete($this->owner, $forecast->refresh()))
        ->toThrow(ValidationException::class, 'En låst prognos för ett stängt räkenskapsår kan inte tas bort.');
});

it('shows each forecast\'s cut-off in Rullande 12 månader and flags one behind the books', function () {
    e5Forecast(['cutoff' => '2026-01', 'revenue_method' => 'AVERAGE']);

    $report = RollingTwelveMonths::build('2026-01');

    expect($report['sources']['2026-04'])->toMatchArray(['cutoff' => '2026-01', 'stale' => true]);

    // Behind the books, the page starts at this month rather than after the cut-off.
    Livewire::test(RollingForecast::class)
        ->assertSet('fran', '2026-04')
        ->assertSee('utfall till och med januari 2026')
        ->assertSee('Prognosen är äldre än bokföringen. Räkna om den eller gör en ny.');

    $fresh = e5Forecast(['cutoff' => '2026-03', 'revenue_method' => 'AVERAGE', 'name' => 'Färsk']);
    app(ManageBudgetVersion::class)->activate($this->owner, $fresh);

    expect(RollingTwelveMonths::build('2026-01')['sources']['2026-04']['stale'])->toBeFalse();

    Livewire::test(RollingForecast::class)
        ->assertSet('fran', '2026-04')
        ->assertDontSee('Prognosen är äldre än bokföringen');
});
