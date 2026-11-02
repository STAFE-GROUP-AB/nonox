<?php

declare(strict_types=1);

use App\Actions\Closing\YearEndClosing;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Company\VatPeriod;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Models\YearEndEntry;
use App\Modules\Inventory\Actions\BookStockValuation;
use App\Modules\Inventory\Actions\PickLists;
use App\Modules\Inventory\Domain\ValuationMethod;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Livewire\ReportsPage;
use App\Modules\Inventory\Models\StockValuation;
use App\Modules\Inventory\Support\StockValuationReport;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * The stock at the year end (ADR-039): the valuation report by FIFO and
 * weighted average, lägsta värdets princip, booking the change against the
 * stock account, and the checklist point. The movements are the ones worked
 * by hand in tests/Unit/Inventory/ValuationTest.php: FIFO 1 250 kr, average
 * 1 200 kr for 10 st on hand.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None])->save();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    $places = Inventory::locations($this->company, $this->owner);
    $this->article = Inventory::article($this->company, $this->owner);
    Inventory::receive($this->company, $this->owner, $this->article, $places['bin1'], '10', '100', '2026-03-01');
    Inventory::receive($this->company, $this->owner, $this->article, $places['bin2'], '10', '120', '2026-04-01');
    $list = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->article->id, 'quantity' => '15']]);
    app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-05-01'));
    Inventory::receive($this->company, $this->owner, $this->article, $places['bin1'], '5', '130', '2026-06-01');

    // 500 kr of stock already on 1460 from an earlier booking.
    Bookkeeping::post($this->owner, $this->company, 50_000, 1460, 1930, '2026-01-02');
});

it('values the stock on the last day by the chosen method', function () {
    $fifo = StockValuationReport::at($this->company, $this->year->end_date);
    expect($fifo->valueOre)->toBe(125_000)->and($fifo->lines[0]['quantity_milli'])->toBe(10_000);
    expect(array_sum(array_column($fifo->lines[0]['locations'], 'value_ore')))->toBe(125_000);

    expect(StockValuationReport::at($this->company, $this->year->end_date, ValuationMethod::Average)->valueOre)->toBe(120_000);

    // On a day in the past: after the pick, before the last receipt.
    expect(StockValuationReport::at($this->company, CarbonImmutable::parse('2026-05-15'))->valueOre)->toBe(60_000);
});

it('books the change against 1460 and 4960, saves the valuation and ticks the checklist', function () {
    $items = collect(ClosingChecklist::for($this->company, $this->year))->keyBy('key');
    expect($items['inventory']['status'])->toBe(ClosingChecklist::TODO)
        ->and($items['inventory']['label'])->toBe('Varulagret är inventerat och värderat');

    $valuation = app(BookStockValuation::class)($this->owner, $this->company, $this->year);

    expect($valuation)
        ->value_ore->toBe(125_000)
        ->book_value_ore->toBe(50_000)
        ->booked_change_ore->toBe(75_000);

    CurrentCompany::set($this->company);
    $voucher = Voucher::query()->with('rows.account')->findOrFail($valuation->voucher_id);
    expect($voucher->voucher_date->toDateString())->toBe('2026-12-31')
        ->and($voucher->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all())->toBe([1460 => 75_000, 4960 => -75_000]);

    expect(YearEndEntry::query()->where('type', YearEndEntry::STOCK_VALUATION)->sole()->amount_ore->ore)->toBe(75_000);
    expect(collect(ClosingChecklist::for($this->company, $this->year))->keyBy('key')['inventory']['status'])->toBe(ClosingChecklist::OK);

    // Booked again with nothing changed: saved, no voucher.
    $again = app(BookStockValuation::class)($this->owner, $this->company, $this->year);
    expect($again->booked_change_ore)->toBe(0)->and($again->voucher_id)->toBeNull();

    expect(fn () => $valuation->update(['value_ore' => 1]))->toThrow(LogicException::class);
});

it('writes the stock down to the net realisable value', function () {
    $this->article->forceFill(['net_realisable_value_ore' => 10_000])->save();

    $valuation = app(BookStockValuation::class)($this->owner, $this->company, $this->year);
    expect($valuation->cost_value_ore)->toBe(125_000)->and($valuation->value_ore)->toBe(100_000)->and($valuation->booked_change_ore)->toBe(50_000);
});

it('uses the weighted average when the company chooses it', function () {
    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $this->company, $this->owner, ['valuation_method' => 'AVERAGE']);

    expect(app(BookStockValuation::class)($this->owner, $this->company, $this->year)->value_ore)->toBe(120_000);
});

it('refuses before the year has ended, after the result is booked, and without the right to close', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-30 10:00:00'));
    expect(fn () => app(BookStockValuation::class)($this->owner, $this->company, $this->year))->toThrow(ValidationException::class);

    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $this->company->unsetRelation('users');
    expect(fn () => app(BookStockValuation::class)($clerk, $this->company, $this->year))->toThrow(AuthorizationException::class);

    Bookkeeping::post($this->owner, $this->company, 100_000, 1930, 3001, '2026-08-01');
    app(YearEndClosing::class)->bookTax($this->owner, $this->year, '0');
    app(YearEndClosing::class)->bookResult($this->owner, $this->year);
    expect(fn () => app(BookStockValuation::class)($this->owner, $this->company, $this->year))->toThrow(ValidationException::class, 'före resultatet');
});

it('keeps the manual checklist point while Lager is off', function () {
    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, confirmed: true);
    $item = collect(ClosingChecklist::for($this->company, $this->year))->keyBy('key')['inventory'];

    expect($item['label'])->toBe('Varulagret är inventerat')->and($item['manual'])->toBeTrue();

    app(YearEndClosing::class)->check($this->owner, $this->year, 'inventory', true);
    expect(collect(ClosingChecklist::for($this->company, $this->year))->keyBy('key')['inventory']['status'])->toBe(ClosingChecklist::OK);
});

it('books from the reports page with a toast', function () {
    Livewire::test(ReportsPage::class)
        ->set('report', 'bokslut')
        ->set('year', $this->year->id)
        ->assertSee('Lagerförändring att bokföra')
        ->call('book')
        ->assertDispatched('toast', message: 'Lagerförändringen är bokförd och lagervärdet är sparat som underlag.');

    expect(StockValuation::query()->count())->toBe(1);
});
