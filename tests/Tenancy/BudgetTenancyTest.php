<?php

declare(strict_types=1);

use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\SaveBudgetLines;
use App\Domain\Budget\BudgetComparison;
use App\Livewire\Accounting\BudgetReport;
use App\Livewire\Accounting\BudgetsPage;
use App\Models\Account;
use App\Models\BudgetVersion;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Budgets (ADR-081 E3): another company's version is invisible on every
 * budget page, its id answers 404, and its ledger never reaches the report.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));

    [$this->ownerB, $this->companyB] = Bookkeeping::company();
    $this->foreign = CurrentCompany::run($this->companyB, function () {
        Bookkeeping::post($this->ownerB, $this->companyB, ore: 777_777_00, date: '2026-02-10');
        $version = app(CreateBudgetVersion::class)($this->ownerB, $this->companyB, [
            'fiscal_year_id' => FiscalYear::query()->whereDate('start_date', '2026-01-01')->value('id'),
            'name' => 'Hemlig budget B',
            'start' => CreateBudgetVersion::EMPTY,
        ]);

        return app(SaveBudgetLines::class)($this->ownerB, $version, [[
            'account_id' => (int) Account::query()->where('number', 3001)->value('id'),
            'cost_center_id' => null, 'project_id' => null, 'amounts' => ['2026-02' => -999_999_00],
        ]]);
    });

    [$this->ownerA, $this->companyA] = Bookkeeping::company();
    CurrentCompany::set($this->companyA);
    $this->actingAs($this->ownerA);
});

it('answers 404 for another company\'s budget version and its CSV file', function () {
    $this->get(route('accounting.budgets.show', ['budgetVersion' => $this->foreign->id]))->assertNotFound();
    $this->get(route('accounting.budgets.csv', ['budgetVersion' => $this->foreign->id]))->assertNotFound();
});

it('never lists or reports another company\'s budget or ledger', function () {
    Livewire::test(BudgetsPage::class)->assertDontSee('Hemlig budget B');

    Livewire::withQueryParams(['budget' => (string) $this->foreign->id])
        ->test(BudgetReport::class)
        ->assertDontSee('Hemlig budget B')
        ->assertDontSee('777')
        ->assertDontSee('999');

    expect(BudgetVersion::query()->find($this->foreign->id))->toBeNull();
});

it('refuses to activate or delete another company\'s version from the list', function () {
    Livewire::test(BudgetsPage::class)->call('delete', $this->foreign->id)->assertNotFound();

    expect(CurrentCompany::run($this->companyB, fn () => BudgetVersion::query()->find($this->foreign->id)))->not->toBeNull();
});

it('keeps company A\'s own report free of company B\'s ledger', function () {
    $own = app(CreateBudgetVersion::class)($this->ownerA, $this->companyA, [
        'fiscal_year_id' => FiscalYear::query()->whereDate('start_date', '2026-01-01')->value('id'),
        'name' => 'Egen', 'start' => CreateBudgetVersion::EMPTY,
    ]);

    expect(BudgetComparison::build($own, '2026-02')['groups'])->toBe([]);
});
