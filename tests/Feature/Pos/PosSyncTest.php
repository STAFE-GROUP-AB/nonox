<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Pos\Actions\PushStock;
use App\Modules\Pos\Actions\RecordPosSale;
use App\Modules\Pos\Actions\SavePosConnectionSettings;
use App\Modules\Pos\Actions\SyncProducts;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Drivers\Zettle\ZettleDriver;
use App\Modules\Pos\Jobs\PushStockJob;
use App\Modules\Pos\Jobs\SyncProductsJob;
use App\Modules\Pos\Models\PosProductLink;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosStockPush;
use App\Modules\Pos\Support\PosApiFailed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Inventory;
use Tests\Support\Pos;

/*
 * Articles and stock out to the till (ADR-042): the Zettle and Onslip
 * product payloads (price including VAT, VAT as Zettle wants it, the
 * product group in Onslip), unchanged articles not sent again, two-way
 * linking, stock sent as changes once per movement and never for the
 * till's own sales.
 */

beforeEach(function () {
    Queue::fake();
    Pos::configure();
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00'));
    [$this->owner, $this->company, $this->places] = Pos::company();
    $this->actingAs($this->owner);
    $this->cup = Inventory::article($this->company, $this->owner, 'V-1', 'Kaffekopp', ['gtin' => '7350000000016', 'description' => 'Stengods, 25 cl']);
});

function zettleInventoryFakes(): void
{
    Http::fake([
        'inventory.izettle.com/organizations/self/locations' => Http::response([
            ['uuid' => 'loc-store', 'type' => 'STORE', 'name' => 'Store', 'default' => true],
            ['uuid' => 'loc-supplier', 'type' => 'SUPPLIER', 'name' => 'Supplier', 'default' => false],
            ['uuid' => 'loc-bin', 'type' => 'BIN', 'name' => 'Bin', 'default' => false],
            ['uuid' => 'loc-sold', 'type' => 'SOLD', 'name' => 'Sold', 'default' => false],
        ]),
        'inventory.izettle.com/organizations/self/inventory' => Http::response(['locationUuid' => 'loc-store', 'variants' => []]),
    ]);
}

it('sends an article to Zettle with the price including VAT, VAT as a string and v1 ids, and not again while unchanged', function () {
    $connection = Pos::connection($this->company, $this->owner);
    Http::fake(['products.izettle.com/*' => Http::response([], 201)]);

    expect(app(SyncProducts::class)($connection)['sent'])->toBe(1);

    Http::assertSent(function (Request $request): bool {
        $variant = $request['variants'][0] ?? [];

        return $request->method() === 'POST' && $request->url() === 'https://products.izettle.com/organizations/self/products'
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-1/', (string) $request['uuid']) === 1
            && $request['name'] === 'Kaffekopp'
            && $variant['price'] === ['amount' => 24875, 'currencyId' => 'SEK']
            && $variant['vatPercentage'] === '25'
            && $variant['barcode'] === '7350000000016'
            && $variant['sku'] === 'V-1';
    });

    $link = PosProductLink::query()->sole();
    expect($link->article_id)->toBe($this->cup->id)->and($link->pushed_hash)->not->toBeNull();

    // Unchanged: nothing is sent.
    expect(app(SyncProducts::class)($connection)['sent'])->toBe(0);
    Http::assertSentCount(1);

    // Changed: an update of the same product.
    app(SaveArticle::class)($this->owner, $this->company, ['number' => 'V-1', 'name' => 'Kaffekopp stor', 'unit' => 'st', 'price' => '219,00', 'vat_rate' => 2500, 'is_stock_item' => true], $this->cup->fresh());
    expect(app(SyncProducts::class)($connection)['sent'])->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->url() === "https://products.izettle.com/organizations/self/products/v2/{$link->external_product_id}" && $request['variants'][0]['price']['amount'] === 27375);
});

it('queues a product sync when an article is saved, once per burst', function () {
    Pos::connection($this->company, $this->owner);

    app(SaveArticle::class)($this->owner, $this->company, ['number' => 'V-9', 'name' => 'Fat', 'unit' => 'st', 'price' => '99,00', 'vat_rate' => 2500]);
    app(SaveArticle::class)($this->owner, $this->company, ['number' => 'V-10', 'name' => 'Skål', 'unit' => 'st', 'price' => '79,00', 'vat_rate' => 2500]);

    Queue::assertPushed(SyncProductsJob::class, 1);
});

it('links the till\'s own products by code first when syncing both ways', function () {
    $connection = Pos::connection($this->company, $this->owner, settings: ['sync_direction' => 'TWO_WAY']);
    Http::fake([
        'products.izettle.com/organizations/self/products' => Http::response([
            ['uuid' => 'prod-existing', 'name' => 'Kopp', 'variants' => [['uuid' => 'var-existing', 'barcode' => '7350000000016']]],
        ]),
        'products.izettle.com/organizations/self/products/v2/*' => Http::response([], 204),
    ]);

    $result = app(SyncProducts::class)($connection);

    expect($result['linked'])->toBe(1)->and($result['sent'])->toBe(1)
        ->and(PosProductLink::query()->sole()->external_product_id)->toBe('prod-existing');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/v2/prod-existing') && $request['variants'][0]['uuid'] === 'var-existing');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('sends an article to Onslip in the product group of its VAT rate, signed with Hawk, and holds it back without a group', function () {
    $connection = Pos::connection($this->company, $this->owner, Provider::Onslip, org: 'demo.example');
    Http::fake(['test.onslip360.com/v1/~demo.example/products/*' => Http::response(['id' => 9001, 'name' => 'Kaffekopp'], 201)]);

    expect(app(SyncProducts::class)($connection)['sent'])->toBe(0);
    Http::assertNothingSent();
    expect($connection->logEntries()->latest('id')->value('message'))->toContain('välj en varugrupp för momssatsen 25 %');

    app(SavePosConnectionSettings::class)($this->owner, $this->company, $connection, ['sync_direction' => 'ONE_WAY', 'push_products' => true, 'push_stock' => true, 'location_id' => null, 'product_groups' => ['2500' => 12]]);
    expect($connection->fresh()->pushesStock())->toBeFalse();

    expect(app(SyncProducts::class)($connection->fresh())['sent'])->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['product-group'] === 12
        && $request['price'] === 248.75
        && $request['ean'] === '7350000000016'
        && str_starts_with($request->header('Authorization')[0], 'Hawk id="key:nonox+token@demo.example"')
        && str_contains($request->header('Authorization')[0], 'hash="'));
    expect(PosProductLink::query()->sole()->external_product_id)->toBe('9001');
});

it('queues stock changes as deltas, SUPPLIER to STORE in and STORE to BIN out, each sent once', function () {
    $connection = Pos::connection($this->company, $this->owner);
    $link = PosProductLink::create(['pos_connection_id' => $connection->id, 'article_id' => $this->cup->id, 'external_product_id' => 'prod-1', 'external_variant_id' => 'var-1']);
    zettleInventoryFakes();

    Inventory::receive($this->company, $this->owner, $this->cup, $this->places['bin1'], '5', '50');
    app(StockLedger::class)->post($this->company, $this->owner, MovementType::Scrap, [['article_id' => $this->cup->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => -2000]], CarbonImmutable::parse('2026-03-10'));

    expect(PosStockPush::query()->orderBy('id')->pluck('delta_milli')->all())->toBe([5000, -2000]);
    Queue::assertPushed(PushStockJob::class, 2);

    expect(app(PushStock::class)($connection))->toBe(2)
        ->and(PosStockPush::query()->pluck('status')->unique()->all())->toBe([PosStockPush::SENT])
        ->and($link->fresh()->stock_tracking)->toBeTrue();

    $pushes = PosStockPush::query()->orderBy('id')->get();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request['changes'][0] === ['productUuid' => 'prod-1', 'variantUuid' => 'var-1', 'fromLocationUuid' => 'loc-supplier', 'toLocationUuid' => 'loc-store', 'change' => 5] && $request['externalUuid'] === $pushes[0]->external_uuid);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request['changes'][0]['fromLocationUuid'] === 'loc-store' && $request['changes'][0]['toLocationUuid'] === 'loc-bin' && $request['changes'][0]['change'] === 2);
    // Tracking is turned on once, and the locations are read once.
    Http::assertSentCount(4);

    expect(app(PushStock::class)($connection))->toBe(0);
});

it('never sends back the till\'s own sales, so nothing loops', function () {
    $connection = Pos::connection($this->company, $this->owner, locationId: $this->places['bin1']->id);
    PosProductLink::create(['pos_connection_id' => $connection->id, 'article_id' => $this->cup->id, 'external_product_id' => 'prod-1', 'external_variant_id' => 'var-1']);
    Inventory::receive($this->company, $this->owner, $this->cup, $this->places['bin1'], '5', '50');
    PosStockPush::query()->delete();

    app(RecordPosSale::class)($connection, app(ZettleDriver::class)->parseSale(Pos::purchase('aaaaaaaa-0000-1000-8000-0000000000aa', [Pos::product('Kaffekopp', '1', 19900, productUuid: 'prod-1')], [['type' => 'IZETTLE_CARD', 'amount' => 24875]], 24875, 4975)), PosSale::WEBHOOK);

    expect(Inventory::level($this->cup, $this->places['bin1']))->toBe(4000)
        ->and(PosStockPush::query()->count())->toBe(0);

    // The same sale through another till's connection would be sent to this one.
    $other = Pos::connection($this->company, $this->owner, Provider::Onslip, locationId: $this->places['bin1']->id, org: 'demo.example');
    app(RecordPosSale::class)($other, app(ZettleDriver::class)->parseSale(Pos::purchase('aaaaaaaa-0000-1000-8000-0000000000bb', [[...Pos::product('Kaffekopp', '1', 19900), 'sku' => 'V-1']], [['type' => 'IZETTLE_CARD', 'amount' => 24875]], 24875, 4975)), PosSale::WEBHOOK);

    expect(PosStockPush::query()->where('pos_connection_id', $connection->id)->pluck('delta_milli')->all())->toBe([-1000]);
});

it('only counts movements in the connection\'s location, and skips articles without a link', function () {
    $connection = Pos::connection($this->company, $this->owner, locationId: $this->places['bin1']->id);
    PosProductLink::create(['pos_connection_id' => $connection->id, 'article_id' => $this->cup->id, 'external_product_id' => 'prod-1', 'external_variant_id' => 'var-1']);
    $plate = Inventory::article($this->company, $this->owner, 'V-2', 'Tallrik');

    Inventory::receive($this->company, $this->owner, $this->cup, $this->places['bin2'], '5', '50');
    Inventory::receive($this->company, $this->owner, $plate, $this->places['bin1'], '5', '50');
    expect(PosStockPush::query()->count())->toBe(0);

    app(StockLedger::class)->transfer($this->company, $this->owner, $this->cup->id, $this->places['bin2']->id, $this->places['bin1']->id, 2000, CarbonImmutable::parse('2026-03-10'));
    expect(PosStockPush::query()->pluck('delta_milli')->all())->toBe([2000]);
});

it('retries a change the till could not take with the same id, and gives up on one it refuses', function () {
    $connection = Pos::connection($this->company, $this->owner);
    PosProductLink::create(['pos_connection_id' => $connection->id, 'article_id' => $this->cup->id, 'external_product_id' => 'prod-1', 'external_variant_id' => 'var-1', 'stock_tracking' => true]);
    Inventory::receive($this->company, $this->owner, $this->cup, $this->places['bin1'], '5', '50');

    Http::fake([
        'inventory.izettle.com/organizations/self/locations' => Http::response([
            ['uuid' => 'loc-store', 'type' => 'STORE'], ['uuid' => 'loc-supplier', 'type' => 'SUPPLIER'], ['uuid' => 'loc-bin', 'type' => 'BIN'],
        ]),
        'inventory.izettle.com/organizations/self/inventory' => Http::sequence()->push(['error' => 'busy'], 503)->push(['variants' => []], 200),
    ]);

    expect(fn () => app(PushStock::class)($connection))->toThrow(PosApiFailed::class);
    $push = PosStockPush::query()->sole();
    expect($push->status)->toBe(PosStockPush::PENDING)->and($push->attempts)->toBe(1);

    expect(app(PushStock::class)($connection))->toBe(1);
    $uuids = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->filter(fn (Request $request): bool => $request->method() === 'PUT')->map(fn (Request $request) => $request['externalUuid'])->unique()->values()->all();
    expect($uuids)->toBe([$push->external_uuid])->and($push->fresh()->status)->toBe(PosStockPush::SENT);

    // Half a unit cannot be sent to Zettle: marked as failed and logged, not retried.
    app(StockLedger::class)->post($this->company, $this->owner, MovementType::Scrap, [['article_id' => $this->cup->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => -500]], CarbonImmutable::parse('2026-03-10'));
    expect(app(PushStock::class)($connection))->toBe(0)
        ->and(PosStockPush::query()->latest('id')->value('status'))->toBe(PosStockPush::FAILED);
});

it('sends nothing while the stock setting is off', function () {
    $connection = Pos::connection($this->company, $this->owner, settings: ['push_stock' => false]);
    PosProductLink::create(['pos_connection_id' => $connection->id, 'article_id' => $this->cup->id, 'external_product_id' => 'prod-1']);

    Inventory::receive($this->company, $this->owner, $this->cup, $this->places['bin1'], '5', '50');

    expect(PosStockPush::query()->count())->toBe(0);
});
