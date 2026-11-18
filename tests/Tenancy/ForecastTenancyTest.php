<?php

declare(strict_types=1);

use App\Actions\Budget\CreateForecast;
use App\Actions\Budget\SaveForecast;
use App\Livewire\Accounting\BudgetsPage;
use App\Livewire\Accounting\RollingForecast;
use App\Models\Account;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Rolling forecasts (ADR-081 E5): another company's forecast answers 404, is
 * never a source, its accounts and cost centres are refused on a row, and its
 * ledger never reaches a forecast or Rullande 12 månader.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));

    [$this->ownerB, $this->companyB] = Bookkeeping::company();
    [$this->foreign, $this->foreignAccount, $this->foreignCostCenter] = CurrentCompany::run($this->companyB, function () {
        Bookkeeping::post($this->ownerB, $this->companyB, ore: 777_777_00, date: '2026-02-10');
        $forecast = app(CreateForecast::class)($this->ownerB, $this->companyB, [
            'fiscal_year_id' => FiscalYear::query()->whereDate('start_date', '2026-01-01')->value('id'),
            'name' => 'Hemlig prognos B',
            'cutoff' => '2026-03',
            'revenue_method' => 'AVERAGE',
            'cost_method' => 'AVERAGE',
            'average_months' => 3,
        ]);

        return [$forecast, (int) Account::query()->where('number', 5010)->value('id'), CostCenter::create(['code' => 'B1', 'name' => 'Hemligt'])->id];
    });

    [$this->ownerA, $this->companyA] = Bookkeeping::company();
    CurrentCompany::set($this->companyA);
    $this->actingAs($this->ownerA);
    $this->yearA = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
});

/** @param array<string, mixed> $input */
function e5TenancyForecast(array $input = []): BudgetVersion
{
    return app(CreateForecast::class)(test()->ownerA, test()->companyA, [
        'fiscal_year_id' => test()->yearA->id, 'name' => 'Egen', 'cutoff' => '2026-03',
        'revenue_method' => 'AVERAGE', 'cost_method' => 'AVERAGE', 'average_months' => 3, ...$input,
    ]);
}

it('answers 404 for another company\'s forecast and its CSV file', function () {
    $this->get(route('accounting.budgets.show', ['budgetVersion' => $this->foreign->id]))->assertNotFound();
    $this->get(route('accounting.budgets.csv', ['budgetVersion' => $this->foreign->id]))->assertNotFound();

    expect(BudgetVersion::query()->find($this->foreign->id))->toBeNull();
});

it('never makes a forecast from another company\'s forecast', function () {
    expect(fn () => e5TenancyForecast(['source_version_id' => $this->foreign->id]))->toThrow(ValidationException::class);
});

it('refuses another company\'s account and cost centre on a forecast row', function () {
    $own = e5TenancyForecast();
    $input = ['revenue_method' => 'AVERAGE', 'cost_method' => 'AVERAGE', 'average_months' => 3];
    $ownAccount = (int) Account::query()->where('number', 5010)->value('id');

    expect(fn () => app(SaveForecast::class)($this->ownerA, $own, [...$input, 'extra_rows' => ["{$this->foreignAccount}-0-0"]]))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveForecast::class)($this->ownerA, $own, [...$input, 'overrides' => ["{$ownAccount}-{$this->foreignCostCenter}-0" => ['2026-05' => 1_00]]]))->toThrow(ValidationException::class);
});

it('keeps another company\'s ledger out of the forecast, the list and Rullande 12 månader', function () {
    $own = e5TenancyForecast();

    expect(BudgetLine::query()->where('budget_version_id', $own->id)->count())->toBe(0);

    Livewire::test(BudgetsPage::class)->assertDontSee('Hemlig prognos B');

    Livewire::withQueryParams(['fran' => '2026-01'])
        ->test(RollingForecast::class)
        ->assertDontSee('Hemlig prognos B')
        ->assertDontSee('777');
});

it('refuses to save another company\'s forecast', function () {
    expect(fn () => app(SaveForecast::class)($this->ownerA, $this->foreign, ['revenue_method' => 'AVERAGE', 'cost_method' => 'AVERAGE', 'average_months' => 3]))
        ->toThrow(AuthorizationException::class);

    expect(CurrentCompany::run($this->companyB, fn () => BudgetLine::query()->where('budget_version_id', $this->foreign->id)->count()))->toBeGreaterThan(0);
});
