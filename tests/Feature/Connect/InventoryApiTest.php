<?php

declare(strict_types=1);

use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Tests\Support\Connect;
use Tests\Support\Inventory;

/*
 * Lager over the API (ADR-039): stocked articles with stock per location,
 * locations, levels, and creating and receiving an inleverans through the
 * same Actions as the app.
 */

beforeEach(function () {
    Connect::setUp();
    [$this->owner, $this->company] = Connect::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->article = Inventory::article($this->company, $this->owner, 'V-1', 'Kaffekopp', ['gtin' => '7310865004703']);
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '80');
    Inventory::article($this->company, $this->owner, 'T-1', 'Tjänst', ['is_stock_item' => false]);
});

it('lists stocked articles with their stock, and hides costs without inventory:manage', function () {
    $view = Connect::apiKey($this->owner, $this->company, ['inventory:view']);

    $this->withToken($view)->getJson('/api/v1/inventory/articles')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', 'V-1')
        ->assertJsonPath('data.0.stock.quantity', '10')
        ->assertJsonPath('data.0.stock.locations.0.code', 'HL-A-01')
        ->assertJsonPath('data.0.purchase_price_ore', null);

    $this->withToken($view)->getJson('/api/v1/inventory/articles?gtin=7310865004703')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($view)->getJson('/api/v1/inventory/articles?gtin=96385074')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($view)->getJson('/api/v1/inventory/articles?number=V-1')->assertOk()->assertJsonPath('data.0.gtin', '7310865004703');
    $this->withToken($view)->getJson('/api/v1/inventory/locations')->assertOk()->assertJsonCount(4, 'data');
    $this->withToken($view)->getJson("/api/v1/inventory/stock-levels?location_id={$this->places['bin1']->id}")->assertOk()
        ->assertJsonPath('data.0.quantity', '10');

    $manage = Connect::apiKey($this->owner, $this->company, ['inventory:view', 'inventory:manage']);
    $this->withToken($manage)->getJson('/api/v1/inventory/articles')->assertJsonPath('data.0.purchase_price_ore', 8000);
});

it('creates and receives an inleverans, once per idempotency key', function () {
    $key = Connect::apiKey($this->owner, $this->company, ['inventory:manage']);
    $body = ['reference' => 'FS-1', 'receive' => true, 'received_on' => '2026-11-02', 'lines' => [
        ['article_id' => $this->article->id, 'location_id' => $this->places['bin2']->id, 'quantity' => '2.5', 'unit_cost_ore' => 7500],
    ]];

    $this->withToken($key)->withHeader('Idempotency-Key', 'inleverans-1')->postJson('/api/v1/inventory/receipts', $body)->assertCreated()
        ->assertJsonPath('data.status', 'RECEIVED')
        ->assertJsonPath('data.lines.0.received', '2.5')
        ->assertJsonPath('data.lines.0.unit_cost_ore', 7500);
    $this->withToken($key)->withHeader('Idempotency-Key', 'inleverans-1')->postJson('/api/v1/inventory/receipts', $body)->assertCreated();

    CurrentCompany::set($this->company);
    expect(StockReceipt::query()->count())->toBe(2)
        ->and(Inventory::level($this->article, $this->places['bin2']))->toBe(2500);

    $this->withToken($key)->withHeader('Idempotency-Key', 'inleverans-2')->postJson('/api/v1/inventory/receipts', ['lines' => [['article_id' => $this->article->id, 'quantity' => 2.5]]])
        ->assertUnprocessable();
});

it('needs the scope, and answers 403 while Lager is off', function () {
    $other = Connect::apiKey($this->owner, $this->company, ['invoices:view']);
    $this->withToken($other)->getJson('/api/v1/inventory/articles')->assertForbidden();

    $view = Connect::apiKey($this->owner, $this->company, ['inventory:view']);
    $this->withToken($view)->withHeader('Idempotency-Key', 'x-1')->postJson('/api/v1/inventory/receipts', ['lines' => []])->assertForbidden();

    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->withToken($view)->getJson('/api/v1/inventory/articles')->assertForbidden();
});
