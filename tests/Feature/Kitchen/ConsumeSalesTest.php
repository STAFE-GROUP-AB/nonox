<?php

declare(strict_types=1);

use App\Models\Voucher;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Kitchen\Actions\ConsumeSales;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\Kitchen\Livewire\KitchenSettingsForm;
use App\Modules\Kitchen\Livewire\VariancePage;
use App\Modules\Kitchen\Models\ConsumptionCredit;
use App\Modules\Kitchen\Models\ConsumptionWait;
use App\Modules\Kitchen\Models\SaleConsumption;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Kitchen;

/*
 * Drawing the till's sales from stock through the recipes (ADR-073 §5):
 * off by default, RECIPE_SALE per ingredient and day, each till row exactly
 * once, a late sale in the next run, never the history before the setting,
 * "Väntar på lager" when negative stock is off, and no voucher.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 03:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Kitchen::company();
    $this->actingAs($this->owner);
    $this->bin = $this->places['bin1'];
    $this->fx = Kitchen::fixtures($this->company, $this->owner, $this->bin, '10');
    $this->a = $this->fx['articles'];
    $this->till = Kitchen::till($this->company, $this->owner, $this->bin);
});

function drawOnSale(string $from = '2026-11-01'): void
{
    app(ModuleManager::class)->updateSettings(KitchenModule::KEY, test()->company, test()->owner, ['draw_on_sale' => true, 'draw_on_sale_from' => $from]);
}

it('draws nothing while the setting is off, which is the default', function () {
    Kitchen::sale($this->till, '2026-11-03', [[$this->a['dish'], 10_000]]);

    expect(app(ConsumeSales::class)($this->company))->toBe(['runs' => 0, 'rows' => 0, 'movements' => 0, 'waiting' => 0])
        ->and(StockMovement::query()->where('type', MovementType::RecipeSale->value)->count())->toBe(0);
});

it('draws each ingredient once per day as RECIPE_SALE, each till row exactly once, without a voucher', function () {
    drawOnSale();
    $vouchers = Voucher::query()->count();
    Kitchen::sale($this->till, '2026-11-03', [[$this->a['dish'], 6_000], [$this->a['dish'], 4_000]]);
    // Today's sales wait for tomorrow night; older than the setting are never drawn.
    Kitchen::sale($this->till, '2026-11-04', [[$this->a['dish'], 1_000]]);
    Kitchen::sale($this->till, '2026-10-30', [[$this->a['dish'], 1_000]]);

    $result = app(ConsumeSales::class)($this->company);

    expect($result)->toMatchArray(['runs' => 1, 'rows' => 2, 'waiting' => 0])
        ->and(StockMovement::query()->where('type', MovementType::RecipeSale->value)->where('article_id', $this->a['champinjoner']->id)->sole()->quantity_milli)->toBe(-1_333)
        ->and(Inventory::level($this->a['champinjoner'], $this->bin))->toBe(10_000 - 1_333)
        ->and(SaleConsumption::query()->count())->toBe(2)
        ->and(Voucher::query()->count())->toBe($vouchers);

    // A second run changes nothing.
    expect(app(ConsumeSales::class)($this->company)['rows'])->toBe(0)
        ->and(StockMovement::query()->where('type', MovementType::RecipeSale->value)->count())->toBe(7);

    // A sale that arrives late for the same day is drawn in the next run.
    Kitchen::sale($this->till, '2026-11-03', [[$this->a['dish'], 10_000]]);
    expect(app(ConsumeSales::class)($this->company)['rows'])->toBe(1)
        ->and(Inventory::level($this->a['champinjoner'], $this->bin))->toBe(10_000 - 2_666);
});

it('lets an ingredient wait for stock when negative stock is off, and tries it again', function () {
    drawOnSale();
    Kitchen::sale($this->till, '2026-11-03', [[$this->a['dish'], 100_000]]); // 13,33 kg of champinjoner, 10 kg in stock

    $result = app(ConsumeSales::class)($this->company);
    $wait = ConsumptionWait::query()->where('article_id', $this->a['champinjoner']->id)->sole();

    expect($result['waiting'])->toBeGreaterThanOrEqual(1)
        ->and($wait)->status->toBe(ConsumptionWait::WAITING)->quantity_milli->toBe(13_330)
        ->and(Inventory::level($this->a['champinjoner'], $this->bin))->toBe(10_000)
        ->and(SaleConsumption::query()->count())->toBe(1);

    Inventory::receive($this->company, $this->owner, $this->a['champinjoner'], $this->bin, '5', '60', '2026-11-04');
    app(ConsumeSales::class)($this->company);

    expect($wait->fresh()->status)->toBe(ConsumptionWait::POSTED)
        ->and(Inventory::level($this->a['champinjoner'], $this->bin))->toBe(15_000 - 13_330);
});

it('lets a person leave a waiting ingredient to the next count', function () {
    drawOnSale();
    Kitchen::sale($this->till, '2026-11-03', [[$this->a['dish'], 100_000]]);
    app(ConsumeSales::class)($this->company);
    $wait = ConsumptionWait::query()->where('article_id', $this->a['champinjoner']->id)->sole();

    Livewire::test(VariancePage::class)
        ->assertSee('Väntar på lager')
        ->call('resolve', $wait->id, false)
        ->assertDispatched('toast', message: 'Ingrediensen lämnas till nästa inventering.');

    expect($wait->fresh()->status)->toBe(ConsumptionWait::DISMISSED);
});

it('switches drawing on only for those who may change the stock settings, from today', function () {
    Livewire::test(KitchenSettingsForm::class, ['company' => $this->company])
        ->set('settings.draw_on_sale', true)
        ->set('settings.target_food_cost', '30')
        ->call('save')
        ->assertDispatched('toast', message: 'Inställningarna för köket är sparade.');

    $settings = app(ModuleManager::class)->settings(KitchenModule::KEY, $this->company);
    expect($settings)->toMatchArray(['draw_on_sale' => true, 'draw_on_sale_from' => '2026-11-04', 'target_food_cost_bp' => 3_000]);

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->company->unsetRelation('users');
    $this->actingAs($bookkeeper);
    expect($bookkeeper->hasTeamPermission($this->company, 'inventory:settings'))->toBeFalse();

    Livewire::test(KitchenSettingsForm::class, ['company' => $this->company])
        ->set('settings.draw_on_sale', false)
        ->call('save')
        ->assertForbidden();
    expect(app(ModuleManager::class)->isEnabled(InventoryModule::KEY, $this->company))->toBeTrue();
});

it('never draws the backlog when the module is turned on again (review P3.5)', function () {
    drawOnSale('2026-11-01');
    app(ModuleManager::class)->disable(KitchenModule::KEY, $this->company, $this->owner, confirmed: true);
    Kitchen::sale($this->till, '2026-11-05', [[$this->a['dish'], 10_000]]);

    $this->travelTo(CarbonImmutable::parse('2026-11-08 03:00:00'));
    app(ModuleManager::class)->enable(KitchenModule::KEY, $this->company, $this->owner);

    expect(app(ModuleManager::class)->settings(KitchenModule::KEY, $this->company)['draw_on_sale_from'])->toBe('2026-11-08')
        ->and(app(ConsumeSales::class)($this->company)['rows'])->toBe(0);
});

it('never puts stock back from returns, and nets them against later sales (review P2.5)', function () {
    drawOnSale();
    Kitchen::sale($this->till, '2026-11-02', [[$this->a['dish'], -2_000]]);

    app(ConsumeSales::class)($this->company);
    expect(StockMovement::query()->where('type', MovementType::RecipeSale->value)->count())->toBe(0)
        ->and(ConsumptionCredit::query()->where('article_id', $this->a['champinjoner']->id)->value('quantity_milli'))->toBe(267)
        ->and(Inventory::level($this->a['champinjoner'], $this->bin))->toBe(10_000);

    Kitchen::sale($this->till, '2026-11-03', [[$this->a['dish'], 10_000]]);
    app(ConsumeSales::class)($this->company);

    expect(StockMovement::query()->where('type', MovementType::RecipeSale->value)->where('article_id', $this->a['champinjoner']->id)->sole()->quantity_milli)->toBe(-(1_333 - 267))
        ->and(ConsumptionCredit::query()->where('article_id', $this->a['champinjoner']->id)->value('quantity_milli'))->toBe(0);
});
