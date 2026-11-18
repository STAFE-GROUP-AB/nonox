<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\Article;
use App\Models\SalesDocument;
use App\Modules\Inventory\Actions\PickLists;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Actions\Shipments;
use App\Modules\Inventory\Livewire\PickListsPage;
use App\Modules\Inventory\Livewire\ShipmentsPage;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\ShipmentPackage;
use App\Modules\Inventory\Models\ShipmentPackageLine;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\ShipmentContents;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * Packing and shipments (ADR-081 E14): a shipment is made from a completed
 * pick list, packages hold what was picked with batch or serial number, the
 * shipment is sent once everything is packed, and the delivery note is a PDF.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->batch = Inventory::article($this->company, $this->owner, 'B-1', 'Mjölk', ['tracking' => 'BATCH']);
    $this->serial = Inventory::article($this->company, $this->owner, 'S-1', 'Borrmaskin', ['tracking' => 'SERIAL']);
    $this->plain = Inventory::article($this->company, $this->owner, 'P-1', 'Kaffekopp');
    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Butik Ett AB', 'payment_terms_days' => 30, 'address_line1' => 'Storgatan 1', 'postal_code' => '111 22', 'city' => 'Stockholm']);
});

/** @param  array<string, mixed>  $row */
function e14Receive(object $test, Article $article, string $quantity, array $row = [], string $date = '2026-03-01'): void
{
    $receipt = app(SaveReceipt::class)($test->owner, $test->company, ['lines' => [['article_id' => $article->id, 'location_id' => $test->places['bin1']->id, 'quantity' => $quantity, 'unit_cost' => '10']]]);
    app(ReceiveStock::class)($test->owner, $test->company, $receipt, [$receipt->lines()->sole()->id => ['quantity' => $quantity, ...$row]], CarbonImmutable::parse($date));
}

/** An order for the article, picked and completed. */
function e14PickedOrder(object $test, Article $article, string $quantity): array
{
    $order = app(SaveSalesDocument::class)($test->owner, $test->company, SalesDocument::ORDER, [
        'customer_id' => $test->customer->id,
        'document_date' => '2026-04-01',
        'rows' => [['article' => $article->number, 'description' => $article->name, 'quantity' => $quantity, 'price' => '20', 'vat_rate' => 2500]],
    ]);
    $list = app(PickLists::class)->fromSource($test->owner, $test->company, $order);
    app(PickLists::class)->complete($test->owner, $test->company, $list, CarbonImmutable::parse('2026-04-02'));

    return [$order, $list->fresh()];
}

it('packs a picked order batch by batch, sends it and records the lots in the packages', function () {
    e14Receive($this, $this->batch, '3', ['lot_code' => 'EARLY', 'expires_on' => '2027-01-01']);
    e14Receive($this, $this->batch, '3', ['lot_code' => 'LATE', 'expires_on' => '2027-06-01'], '2026-03-02');
    [$order, $list] = e14PickedOrder($this, $this->batch, '4');

    $shipments = app(Shipments::class);
    $shipment = $shipments->fromPickList($this->owner, $this->company, $list);
    expect($shipment->status)->toBe(Shipment::DRAFT)
        ->and($shipment->sales_document_id)->toBe($order->id)
        ->and($shipment->customer_id)->toBe($this->customer->id)
        ->and($shipments->fromPickList($this->owner, $this->company, $list)->id)->toBe($shipment->id);

    $early = StockLot::query()->where('code', 'EARLY')->firstOrFail();
    $late = StockLot::query()->where('code', 'LATE')->firstOrFail();
    $one = $shipments->addPackage($this->owner, $this->company, $shipment, ['weight' => '2,5', 'length' => '30', 'width' => '20', 'height' => '12,5']);
    expect($one->weight_grams)->toBe(2500)->and($one->length_mm)->toBe(300)->and($one->height_mm)->toBe(125);

    expect(fn () => $shipments->ship($this->owner, $this->company, $shipment, 'DHL', '123', CarbonImmutable::parse('2026-04-03')))->toThrow(ValidationException::class)
        ->and(fn () => $shipments->pack($this->owner, $this->company, $one, $this->batch->id, $early->id, '4'))->toThrow(ValidationException::class)
        ->and(fn () => $shipments->pack($this->owner, $this->company, $one, $this->batch->id, null, '1'))->toThrow(ValidationException::class)
        ->and(fn () => $shipments->pack($this->owner, $this->company, $one, $this->batch->id, $late->id, '0'))->toThrow(ValidationException::class);

    $shipments->pack($this->owner, $this->company, $one, $this->batch->id, $early->id, '3');
    $two = $shipments->addPackage($this->owner, $this->company, $shipment);

    // An empty package and goods still outside a package both stop the shipment.
    expect(fn () => $shipments->ship($this->owner, $this->company, $shipment, 'DHL', '123', CarbonImmutable::parse('2026-04-03')))->toThrow(ValidationException::class);

    $shipments->pack($this->owner, $this->company, $two, $this->batch->id, $late->id, '1');
    expect(ShipmentContents::remaining($shipment))->toBe([]);

    $shipments->ship($this->owner, $this->company, $shipment, 'DHL', '1234567890', CarbonImmutable::parse('2026-04-03'));
    $shipment = $shipment->fresh();

    expect($shipment->status)->toBe(Shipment::SHIPPED)
        ->and($shipment->carrier)->toBe('DHL')
        ->and($shipment->tracking_number)->toBe('1234567890')
        ->and($shipment->shipped_at->toDateString())->toBe('2026-04-03')
        ->and($shipment->ship_to)->toContain('Butik Ett AB')->toContain('Storgatan 1')
        ->and(ShipmentPackageLine::query()->orderBy('id')->get()->map(fn ($line) => [$line->lot_id, $line->quantity_milli])->all())->toBe([[$early->id, 3000], [$late->id, 1000]])
        ->and(ShipmentContents::delivery($order))->toBe([$this->batch->id => ['article_id' => $this->batch->id, 'ordered_milli' => 4000, 'delivered_milli' => 4000]]);

    // The ledger was not touched by packing: only the pick movements exist besides the receipts.
    expect(StockMovement::query()->where('type', 'PICK')->count())->toBe(2);

    // A sent shipment is final.
    expect(fn () => $shipments->addPackage($this->owner, $this->company, $shipment))->toThrow(ValidationException::class)
        ->and(fn () => $shipments->cancel($this->owner, $this->company, $shipment))->toThrow(ValidationException::class)
        ->and(fn () => $shipments->unpack($this->owner, $this->company, ShipmentPackageLine::query()->firstOrFail()))->toThrow(ValidationException::class);

    // The tracking number can still be corrected.
    $shipments->setTracking($this->owner, $this->company, $shipment, 'PostNord', 'ABC');
    expect($shipment->fresh()->tracking_number)->toBe('ABC');
});

it('lists an unshipped order as undelivered', function () {
    e14Receive($this, $this->plain, '5');
    [$order, $list] = e14PickedOrder($this, $this->plain, '2');
    $shipment = app(Shipments::class)->fromPickList($this->owner, $this->company, $list);

    expect(ShipmentContents::delivery($order)[$this->plain->id]['delivered_milli'])->toBe(0);

    app(Shipments::class)->packRemaining($this->owner, $this->company, $shipment);
    expect(ShipmentContents::delivery($order)[$this->plain->id]['delivered_milli'])->toBe(0);

    app(Shipments::class)->ship($this->owner, $this->company, $shipment, '', '', CarbonImmutable::parse('2026-04-03'));
    expect(ShipmentContents::delivery($order)[$this->plain->id]['delivered_milli'])->toBe(2000)
        ->and($shipment->fresh()->carrier)->toBeNull();
});

it('packs every serial number of a picked quantity in one go and keeps each one on its own line', function () {
    e14Receive($this, $this->serial, '3', ['serials' => "SN-1\nSN-2\nSN-3"]);
    [, $list] = e14PickedOrder($this, $this->serial, '2');
    $shipments = app(Shipments::class);
    $shipment = $shipments->fromPickList($this->owner, $this->company, $list);

    $package = $shipments->packRemaining($this->owner, $this->company, $shipment);
    $codes = $package->lines()->with('lot')->get()->map(fn ($line) => [$line->lot->code, $line->quantity_milli])->all();

    expect($codes)->toHaveCount(2)
        ->and(array_column($codes, 1))->toBe([1000, 1000])
        ->and(fn () => $shipments->packRemaining($this->owner, $this->company, $shipment))->toThrow(ValidationException::class);

    // A serial number is one unit: it cannot be packed twice.
    $other = $shipments->addPackage($this->owner, $this->company, $shipment);
    expect(fn () => $shipments->pack($this->owner, $this->company, $other, $this->serial->id, $package->lines()->first()->lot_id, '1'))->toThrow(ValidationException::class);
});

it('only packs a completed pick list, and only once', function () {
    e14Receive($this, $this->plain, '5');
    $open = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->plain->id, 'quantity' => '2']]);

    expect(fn () => app(Shipments::class)->fromPickList($this->owner, $this->company, $open))->toThrow(ValidationException::class);

    app(PickLists::class)->complete($this->owner, $this->company, $open, CarbonImmutable::parse('2026-04-02'));
    $shipments = app(Shipments::class);
    $shipment = $shipments->fromPickList($this->owner, $this->company, $open->fresh());
    expect($shipment->sales_document_id)->toBeNull();

    // The database allows one live shipment per pick list.
    expect(fn () => DB::transaction(fn () => Shipment::create(['number' => 99, 'pick_list_id' => $open->id, 'status' => 'DRAFT'])))->toThrow(QueryException::class);

    // A cancelled shipment frees the list, and its packages go with it.
    $shipments->packRemaining($this->owner, $this->company, $shipment);
    $shipments->cancel($this->owner, $this->company, $shipment);
    expect(ShipmentPackage::query()->count())->toBe(0);

    $again = $shipments->fromPickList($this->owner, $this->company, $open->fresh());
    expect($again->id)->not->toBe($shipment->id)->and($again->number)->toBeGreaterThan($shipment->number);
});

it('reads measures in kilograms and centimetres and refuses nonsense', function () {
    e14Receive($this, $this->plain, '5');
    [, $list] = e14PickedOrder($this, $this->plain, '1');
    $shipments = app(Shipments::class);
    $package = $shipments->addPackage($this->owner, $this->company, $shipments->fromPickList($this->owner, $this->company, $list));

    $shipments->updatePackage($this->owner, $this->company, $package, ['weight' => '12', 'length' => '', 'width' => '40,5', 'height' => '1']);
    $package = $package->fresh();
    expect($package->weight_grams)->toBe(12000)->and($package->length_mm)->toBeNull()->and($package->width_mm)->toBe(405)->and($package->height_mm)->toBe(10)
        ->and(fn () => $shipments->updatePackage($this->owner, $this->company, $package, ['weight' => 'tung']))->toThrow(ValidationException::class)
        ->and(fn () => $shipments->updatePackage($this->owner, $this->company, $package, ['width' => '-4']))->toThrow(ValidationException::class)
        ->and(fn () => $shipments->updatePackage($this->owner, $this->company, $package, ['weight' => '99999999']))->toThrow(ValidationException::class);
});

it('lets Lagerpersonal pack and keeps everyone without the right out', function () {
    e14Receive($this, $this->plain, '5');
    [, $list] = e14PickedOrder($this, $this->plain, '1');

    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $this->actingAs($clerk);
    $shipment = app(Shipments::class)->fromPickList($clerk, $this->company, $list);
    expect($shipment)->toBeInstanceOf(Shipment::class);

    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->actingAs($auditor);
    $company = $this->company->fresh();
    expect(fn () => app(Shipments::class)->addPackage($auditor, $company, $shipment))->toThrow(AuthorizationException::class);
    Livewire::test(ShipmentsPage::class)->assertOk();
});

it('shows the page, packs through it and prints the delivery note', function () {
    e14Receive($this, $this->batch, '4', ['lot_code' => 'LOT-9', 'expires_on' => '2027-01-01']);
    [, $list] = e14PickedOrder($this, $this->batch, '2');

    Livewire::test(PickListsPage::class)->set('status', 'alla')->assertSee('Packa')->call('pack', $list->id)->assertRedirect();
    $shipment = Shipment::query()->sole();

    $page = Livewire::withQueryParams(['forsandelse' => $shipment->id])->test(ShipmentsPage::class)
        ->assertSee('Försändelse '.$shipment->number)->assertSee('LOT-9')
        ->call('addPackage')
        ->assertSee('Kolli 1');

    $package = ShipmentPackage::query()->sole();
    $page->set("measures.{$package->id}.weight", '3')->call('saveMeasures', $package->id)
        ->set("put.{$package->id}.item", $this->batch->id.':'.StockLot::query()->sole()->id)
        ->set("put.{$package->id}.quantity", '5')->call('putIn', $package->id)
        ->assertHasErrors(["put.{$package->id}.quantity"])
        ->set("put.{$package->id}.quantity", '2')->call('putIn', $package->id)
        ->assertHasNoErrors()
        ->set('carrier', 'Schenker')->set('tracking', 'TRK-1')->set('date', '2026-04-05')->call('ship')
        ->assertSee('Skickad');

    expect($package->fresh()->weight_grams)->toBe(3000)
        ->and($shipment->fresh()->status)->toBe(Shipment::SHIPPED);

    $response = $this->get(route('inventory.shipments.deliveryNote', $shipment->id))->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(str_starts_with($response->getContent(), '%PDF'))->toBeTrue();

    $html = view('inventory::pdf.delivery-note', ['shipment' => $shipment->fresh()->load(['packages.lines.article', 'packages.lines.lot', 'customer', 'pickList', 'salesDocument']), 'company' => $this->company])->render();
    expect($html)->toContain('Butik Ett AB')->toContain('LOT-9')->toContain('TRK-1')->not->toContain('Pris');
});

it('runs a fixed number of queries on the page whatever the number of packages', function () {
    e14Receive($this, $this->plain, '60');

    $count = function (int $packages): int {
        $list = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->plain->id, 'quantity' => (string) $packages]]);
        app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-04-02'));
        $shipments = app(Shipments::class);
        $shipment = $shipments->fromPickList($this->owner, $this->company, $list->fresh());

        for ($i = 0; $i < $packages; $i++) {
            $package = $shipments->addPackage($this->owner, $this->company, $shipment, ['weight' => '1']);
            $shipments->pack($this->owner, $this->company, $package, $this->plain->id, null, '1');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['forsandelse' => $shipment->id, 'visa' => 'alla'])->test(ShipmentsPage::class)->assertSee('Kolli '.$packages);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->get(route('inventory.shipments.deliveryNote', $shipment->id))->assertOk();

        return $queries;
    };

    $count(1);
    expect($count(2))->toBe($count(12));
});
