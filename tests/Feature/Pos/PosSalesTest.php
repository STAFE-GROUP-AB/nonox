<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\ModuleManager;
use App\Modules\Pos\Actions\LinkPosSaleRow;
use App\Modules\Pos\Actions\RecordPosSale;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Drivers\Onslip\OnslipDriver;
use App\Modules\Pos\Drivers\Zettle\ZettleDriver;
use App\Modules\Pos\Jobs\ProcessPosWebhookJob;
use App\Modules\Pos\Livewire\PosPage;
use App\Modules\Pos\Models\PosProductLink;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleRow;
use App\Modules\Pos\Models\PosWebhookMessage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Pos;

/*
 * Sales in (ADR-042): the Zettle webhook (signature, age, replays), each
 * purchase recorded exactly once, lines matched to articles and taken out
 * of Lager, the Omatchade flow, the reconcile command and Onslip receipts.
 */

beforeEach(function () {
    Queue::fake();
    Pos::configure();
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00'));
    [$this->owner, $this->company, $this->places] = Pos::company();
    $this->actingAs($this->owner);
    $this->connection = Pos::connection($this->company, $this->owner, locationId: $this->places['bin1']->id);
    $this->cup = Inventory::article($this->company, $this->owner, 'V-1', 'Kaffekopp', ['gtin' => '7350000000016']);
    $this->plate = Inventory::article($this->company, $this->owner, 'V-2', 'Tallrik');
    Inventory::receive($this->company, $this->owner, $this->cup, $this->places['bin1'], '10', '50');
    Inventory::receive($this->company, $this->owner, $this->plate, $this->places['bin1'], '10', '40');
});

function cupSale(string $uuid = 'aaaaaaaa-0000-1000-8000-000000000001', array $extra = []): array
{
    return Pos::purchase($uuid, [
        Pos::product('Kaffekopp', '2', 39800, barcode: '7350000000016'),
        Pos::product('Fritt belopp', '1', 8000, type: 'CUSTOM_AMOUNT'),
    ], [['type' => 'IZETTLE_CARD', 'amount' => 59750]], 59750, 11950, extra: $extra);
}

function postWebhook(object $test, array $envelope, ?string $token = null): TestResponse
{
    return $test->call('POST', route('pos.webhook.zettle', ['token' => $token ?? $test->connection->webhook_token]), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_IZETTLE_SIGNATURE' => $envelope['signature'],
    ], $envelope['body']);
}

it('accepts a signed webhook, stores it once and queues it', function () {
    $envelope = Pos::envelope(cupSale(), 'msg-1');

    postWebhook($this, $envelope)->assertOk();
    postWebhook($this, $envelope)->assertOk();

    expect(PosWebhookMessage::query()->count())->toBe(1);
    Queue::assertPushed(ProcessPosWebhookJob::class, 1);

    $message = PosWebhookMessage::query()->sole();
    (new ProcessPosWebhookJob($this->company->id, $message->id))->handle(app(RecordPosSale::class), app(ModuleManager::class));

    $sale = PosSale::query()->sole();
    expect($sale->total_ore)->toBe(59750)
        ->and($sale->source)->toBe(PosSale::WEBHOOK)
        ->and($sale->business_date->toDateString())->toBe('2026-03-10')
        ->and($sale->vat_totals)->toBe(['2500' => ['net' => 47800, 'vat' => 11950]])
        ->and($sale->payments)->toBe(['CARD' => 59750])
        ->and($sale->matched_count)->toBe(1)
        ->and(Inventory::level($this->cup, $this->places['bin1']))->toBe(8000)
        ->and($message->fresh()->processed_at)->not->toBeNull();
});

it('refuses a bad signature, an unknown address, and a message older than seven days', function () {
    $envelope = Pos::envelope(cupSale(), 'msg-1');

    postWebhook($this, ['body' => $envelope['body'], 'signature' => str_repeat('0', 64)])->assertUnauthorized();
    postWebhook($this, Pos::envelope(cupSale(), 'msg-2', key: 'another-key'))->assertUnauthorized();
    postWebhook($this, $envelope, str_repeat('x', 48))->assertNotFound();
    postWebhook($this, Pos::envelope(cupSale(), 'msg-3', now()->subDays(8)->utc()->format('Y-m-d\TH:i:s.v\Z')))->assertUnauthorized();
    postWebhook($this, Pos::envelope(cupSale(), 'msg-4', now()->subDays(6)->utc()->format('Y-m-d\TH:i:s.v\Z')))->assertOk();

    expect(PosWebhookMessage::query()->pluck('message_id')->all())->toBe(['msg-4']);
});

it('answers the test message without a key and stores nothing', function () {
    $this->connection->forceFill(['credentials' => [...$this->connection->credentials, 'signing_key' => null]])->save();

    postWebhook($this, ['body' => (string) json_encode(['eventName' => 'TestMessage', 'messageId' => 't', 'payload' => '{}']), 'signature' => ''])->assertOk();

    expect(PosWebhookMessage::query()->count())->toBe(0);
});

it('answers 404 once the till is disconnected', function () {
    $envelope = Pos::envelope(cupSale(), 'msg-1');
    $this->connection->forceFill(['status' => 'DISCONNECTED'])->save();

    postWebhook($this, $envelope)->assertNotFound();
});

it('records a purchase once: a replay, the nightly fetch or a second worker change nothing', function () {
    $driver = app(ZettleDriver::class);
    $record = app(RecordPosSale::class);
    $sale = $driver->parseSale(cupSale());

    expect($record($this->connection, $sale, PosSale::WEBHOOK))->toBeInstanceOf(PosSale::class)
        ->and($record($this->connection, $sale, PosSale::RECONCILE))->toBeNull();

    // A second worker that lost the race finds the key taken.
    $other = $driver->parseSale(cupSale('bbbbbbbb-0000-1000-8000-000000000002'));
    DB::table('pos_sales')->insert([
        'company_id' => $this->company->id, 'pos_connection_id' => $this->connection->id, 'provider' => 'zettle', 'external_id' => $other->externalId,
        'source' => 'WEBHOOK', 'sold_at' => now(), 'business_date' => '2026-03-10', 'currency' => 'SEK', 'total_ore' => 1, 'vat_ore' => 0,
        'vat_totals' => '{}', 'payments' => '{}', 'payload' => '{}', 'payload_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect($record($this->connection, $other, PosSale::WEBHOOK))->toBeNull();

    expect(PosSale::query()->count())->toBe(2)
        ->and(StockMovement::query()->where('type', MovementType::PosSale->value)->count())->toBe(1)
        ->and(Inventory::level($this->cup, $this->places['bin1']))->toBe(8000);
});

it('matches on the till product link, the GTIN, the SKU and the article number, and skips lines without goods', function () {
    PosProductLink::create(['pos_connection_id' => $this->connection->id, 'article_id' => $this->plate->id, 'external_product_id' => 'prod-plate']);
    $sale = app(ZettleDriver::class)->parseSale(Pos::purchase('cccccccc-0000-1000-8000-000000000003', [
        Pos::product('Tallrik', '1', 10000, productUuid: 'prod-plate'),
        Pos::product('Kopp', '1.5', 10000, barcode: '7350000000016'),
        [...Pos::product('Kopp via nummer', '1', 10000), 'sku' => 'V-1'],
        Pos::product('Presentkort', '1', 50000, type: 'GIFTCARD'),
    ], [['type' => 'IZETTLE_CASH', 'amount' => 87500]], 87500, 7500));

    $record = app(RecordPosSale::class)($this->connection, $sale, PosSale::WEBHOOK);

    expect($record->rows->pluck('status')->all())->toBe(['MATCHED', 'MATCHED', 'MATCHED', 'SKIPPED'])
        ->and(Inventory::level($this->plate, $this->places['bin1']))->toBe(9000)
        ->and(Inventory::level($this->cup, $this->places['bin1']))->toBe(7500);
});

it('puts a refund back in stock, also when the till sends positive quantities', function () {
    $sale = app(ZettleDriver::class)->parseSale(Pos::purchase('dddddddd-0000-1000-8000-000000000004', [
        Pos::product('Kaffekopp', '1', 19900, barcode: '7350000000016'),
    ], [['type' => 'IZETTLE_CARD', 'amount' => -24875]], -24875, -4975, extra: ['refund' => true]));

    $record = app(RecordPosSale::class)($this->connection, $sale, PosSale::WEBHOOK);

    expect($record->is_refund)->toBeTrue()
        ->and($record->vat_totals)->toBe(['2500' => ['net' => -19900, 'vat' => -4975]])
        ->and($record->rows->first()->quantity_milli)->toBe(-1000)
        ->and(Inventory::level($this->cup, $this->places['bin1']))->toBe(11000);
});

it('keeps unmatched lines for a person, who links them and moves the stock', function () {
    $driver = app(ZettleDriver::class);
    $record = app(RecordPosSale::class);
    $record($this->connection, $driver->parseSale(Pos::purchase('eeeeeeee-0000-1000-8000-000000000005', [Pos::product('Okänd mugg', '1', 8000, productUuid: 'prod-mug')], [['type' => 'SWISH', 'amount' => 10000]], 10000, 2000)), PosSale::WEBHOOK);
    $record($this->connection, $driver->parseSale(Pos::purchase('eeeeeeee-0000-1000-8000-000000000006', [Pos::product('Okänd mugg', '2', 16000, productUuid: 'prod-mug')], [['type' => 'SWISH', 'amount' => 20000]], 20000, 4000)), PosSale::WEBHOOK);

    $rows = PosSaleRow::query()->where('status', PosSaleRow::UNMATCHED)->orderBy('id')->get();
    expect($rows)->toHaveCount(2);

    $this->get(route('pos.index'))->assertOk()->assertSee('Okänd mugg');

    Livewire::test(PosPage::class)
        ->set("articleFor.{$rows[0]->id}", 'finns-inte')
        ->call('linkRow', $rows[0]->id)
        ->assertHasErrors("articleFor.{$rows[0]->id}");

    Livewire::test(PosPage::class)
        ->set("articleFor.{$rows[0]->id}", 'V-2')
        ->call('linkRow', $rows[0]->id)
        ->assertDispatched('toast', message: '2 rader är kopplade till V-2 och dragna från lagret.');

    expect(PosSaleRow::query()->where('status', PosSaleRow::UNMATCHED)->count())->toBe(0)
        ->and(Inventory::level($this->plate, $this->places['bin1']))->toBe(7000)
        ->and(PosProductLink::query()->where('external_product_id', 'prod-mug')->value('article_id'))->toBe($this->plate->id)
        ->and(PosSale::query()->sum('unmatched_count'))->toBe(0);

    // The next sale of the product is matched by the new link.
    $next = $record($this->connection, $driver->parseSale(Pos::purchase('eeeeeeee-0000-1000-8000-000000000007', [Pos::product('Okänd mugg', '1', 8000, productUuid: 'prod-mug')], [['type' => 'SWISH', 'amount' => 10000]], 10000, 2000)), PosSale::WEBHOOK);
    expect($next->matched_count)->toBe(1);
});

it('waits with a line when the stock does not allow it, and posts it after the delivery', function () {
    $record = app(RecordPosSale::class)($this->connection, app(ZettleDriver::class)->parseSale(Pos::purchase('ffffffff-0000-1000-8000-000000000008', [Pos::product('Kaffekopp', '12', 238800, barcode: '7350000000016')], [['type' => 'IZETTLE_CARD', 'amount' => 298500]], 298500, 59700)), PosSale::WEBHOOK);
    $row = $record->rows->first();

    expect($row->status)->toBe(PosSaleRow::UNMATCHED)
        ->and($row->article_id)->toBe($this->cup->id)
        ->and(Inventory::level($this->cup, $this->places['bin1']))->toBe(10000)
        ->and(fn () => app(LinkPosSaleRow::class)($this->owner, $this->company, $row, $this->cup->id))->toThrow(ValidationException::class);

    Inventory::receive($this->company, $this->owner, $this->cup, $this->places['bin1'], '5', '50');
    expect(app(LinkPosSaleRow::class)($this->owner, $this->company, $row->fresh(), $this->cup->id))->toBe(1)
        ->and(Inventory::level($this->cup, $this->places['bin1']))->toBe(3000);
});

it('lets only inventory:manage link or set aside a line', function () {
    $record = app(RecordPosSale::class)($this->connection, app(ZettleDriver::class)->parseSale(Pos::purchase('abababab-0000-1000-8000-000000000009', [Pos::product('Okänd', '1', 8000)], [['type' => 'IZETTLE_CASH', 'amount' => 10000]], 10000, 2000)), PosSale::WEBHOOK);
    $row = $record->rows->first();
    $clerk = Bookkeeping::member($this->company, InventoryModule::ROLE);

    expect(fn () => app(LinkPosSaleRow::class)($clerk, $this->company, $row, $this->cup->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(LinkPosSaleRow::class)->ignore($clerk, $this->company, $row))->toThrow(AuthorizationException::class);

    Livewire::actingAs($clerk)->test(PosPage::class)->assertSee('Väntar på att någon med lagerbehörighet kopplar raden');

    app(LinkPosSaleRow::class)->ignore($this->owner, $this->company, $row);
    expect($row->fresh()->status)->toBe(PosSaleRow::SKIPPED);
});

it('fetches a period of purchases page by page, records the new ones and can count without writing', function () {
    Http::fake([
        'purchase.izettle.com/purchases/v2*' => Http::sequence()
            ->push(['purchases' => [cupSale('11111111-0000-1000-8000-000000000001')], 'lastPurchaseHash' => 'h1'])
            ->push(['purchases' => [cupSale('11111111-0000-1000-8000-000000000001')], 'lastPurchaseHash' => 'h1'])
            ->push(['purchases' => [cupSale('11111111-0000-1000-8000-000000000001'), cupSale('22222222-0000-1000-8000-000000000002')], 'lastPurchaseHash' => 'h2']),
        'finance.izettle.com/*' => Http::response(['data' => [
            ['timestamp' => '2026-03-10T10:20:00.000+0000', 'amount' => -1050, 'originatorTransactionType' => 'PAYMENT_FEE', 'originatingTransactionUuid' => 'pay-1'],
        ]]),
    ]);

    $this->artisan('pos:reconcile-sales', ['--company' => $this->company->id, '--dry-run' => true])->expectsOutputToContain('Dry run: 1 sales would be recorded.')->assertSuccessful();
    expect(PosSale::query()->count())->toBe(0);

    $this->artisan('pos:reconcile-sales', ['--company' => $this->company->id])->expectsOutputToContain('Recorded 1 new sales.')->assertSuccessful();
    $this->artisan('pos:reconcile-sales', ['--company' => $this->company->id])->expectsOutputToContain('Recorded 1 new sales.')->assertSuccessful();

    expect(PosSale::query()->count())->toBe(2)
        ->and(PosSale::query()->pluck('source')->unique()->all())->toBe([PosSale::RECONCILE])
        ->and(DB::table('pos_fees')->where('company_id', $this->company->id)->value('amount_ore'))->toBe(1050)
        ->and(DB::table('pos_fees')->where('company_id', $this->company->id)->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'purchases/v2') && str_contains($request->url(), 'limit=500') && str_contains($request->url(), 'startDate=2026-03-07T23'));
});

it('reads Onslip receipts per till with Hawk-signed calls and leaves credit receipts out', function () {
    $onslip = Pos::connection($this->company, $this->owner, Provider::Onslip, locationId: $this->places['bin1']->id, org: 'demo.example');
    PosProductLink::create(['pos_connection_id' => $onslip->id, 'article_id' => $this->cup->id, 'external_product_id' => '501']);
    $receipt = fn (int $id, string $type, float $total) => [
        'id' => $id, 'type' => 'receipt', 'date' => '2026-03-10T09:30:00.000Z', 'client' => 7, 'currency' => 'SEK',
        'receipt' => [
            'type' => $type, 'total-amount' => $total, 'total-vat-amount' => 49.75, 'rounding' => 0,
            'items' => [['product' => 501, 'product-name' => 'Kaffekopp', 'quantity' => 1, 'price' => 248.75, 'type' => 'goods']],
            'vat-summaries' => [['vat-rate' => 25, 'vat-amount' => 49.75, 'net-amount' => 199, 'gross-amount' => 248.75]],
            'payments' => [['method' => 'card', 'amount' => 248.75]],
        ],
    ];

    Http::fake([
        'purchase.izettle.com/*' => Http::response(['purchases' => []]),
        'finance.izettle.com/*' => Http::response(['data' => []]),
        'test.onslip360.com/v1/~demo.example/tills/*' => Http::response([['id' => 7, 'name' => 'Kassa 1']], 200, ['X-Total-Count' => '1']),
        'test.onslip360.com/v1/~demo.example/journal/7/*' => Http::response([$receipt(1, 'sale', 248.75), $receipt(2, 'credit', 248.75)], 200, ['X-Total-Count' => '2']),
    ]);

    $this->artisan('pos:reconcile-sales', ['--company' => $this->company->id])->assertSuccessful();

    $sale = PosSale::query()->where('provider', 'onslip')->sole();
    expect($sale->external_id)->toBe('7:1')
        ->and($sale->total_ore)->toBe(24875)
        ->and($sale->vat_totals)->toBe(['2500' => ['net' => 19900, 'vat' => 4975]])
        ->and($sale->payments)->toBe(['CARD' => 24875])
        ->and(Inventory::level($this->cup, $this->places['bin1']))->toBe(9000);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('Authorization')[0] ?? '', 'Hawk id="key:nonox+token@demo.example"') && str_contains($request->url(), 'o=0;c=500'));
});

it('reads an Onslip return as a refund with the change kept out of the sale', function () {
    $driver = app(OnslipDriver::class);
    $sale = $driver->parseSale([
        'id' => 3, 'type' => 'receipt', 'date' => '2026-03-10T09:30:00.000Z', 'nonox_till' => 7,
        'receipt' => [
            'type' => 'return', 'total-amount' => 100, 'total-vat-amount' => 20,
            'items' => [['product' => 501, 'product-name' => 'Kopp', 'quantity' => 1, 'price' => 100]],
            'vat-summaries' => [['vat-rate' => 25, 'vat-amount' => 20, 'net-amount' => 80]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ],
    ]);
    $cash = $driver->parseSale([
        'id' => 4, 'type' => 'receipt', 'date' => '2026-03-10T09:30:00.000Z', 'nonox_till' => 7,
        'receipt' => [
            'type' => 'sale', 'total-amount' => 95, 'change' => 5, 'total-vat-amount' => 19,
            'items' => [], 'vat-summaries' => [['vat-rate' => 25, 'vat-amount' => 19, 'net-amount' => 76]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ],
    ]);

    expect($sale->isRefund)->toBeTrue()
        ->and($sale->totalOre)->toBe(-10000)
        ->and($sale->rows[0]->quantityMilli)->toBe(-1000)
        ->and($sale->payments)->toBe(['CASH' => -10000])
        ->and($cash->payments)->toBe(['CASH' => 9500])
        ->and($cash->warnings)->toBe([]);
});
