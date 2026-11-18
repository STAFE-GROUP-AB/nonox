<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Privacy\EraseRegisterEntry;
use App\Modules\Inventory\Actions\PickLists;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Actions\Shipments;
use App\Modules\Inventory\Livewire\ShipmentsPage;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\ShipmentPackageLine;
use App\Support\Privacy\DataSubjectExport;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * Shipments and personal data (ADR-081 E14): retention, erasure, the data
 * subject's extract, and the guards on carrier, tracking number and date.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->serial = Inventory::article($this->company, $this->owner, 'S-1', 'Borrmaskin', ['tracking' => 'SERIAL']);
    $this->plain = Inventory::article($this->company, $this->owner, 'P-1', 'Kaffekopp');
});

function e14pReceiveSerials(object $test): void
{
    $receipt = app(SaveReceipt::class)($test->owner, $test->company, ['lines' => [['article_id' => $test->serial->id, 'location_id' => $test->places['bin1']->id, 'quantity' => '2', 'unit_cost' => '10']]]);
    app(ReceiveStock::class)($test->owner, $test->company, $receipt, [$receipt->lines()->sole()->id => ['quantity' => '2', 'serials' => "SN-A\nSN-B"]], CarbonImmutable::parse('2026-03-01'));
}

/** A shipment shipped to a customer that has no order or invoice. */
function e14pShipped(object $test, string $shippedOn = '2026-04-03'): array
{
    $customer = app(SaveCustomer::class)($test->owner, $test->company, ['name' => 'Privat Person', 'payment_terms_days' => 30, 'address_line1' => 'Björkvägen 4', 'postal_code' => '111 22', 'city' => 'Stockholm']);
    e14pReceiveSerials($test);
    $list = app(PickLists::class)->manual($test->owner, $test->company, [['article_id' => $test->serial->id, 'quantity' => '1']]);
    $list->update(['customer_id' => $customer->id]);
    app(PickLists::class)->complete($test->owner, $test->company, $list, CarbonImmutable::parse('2026-04-02'));
    $shipments = app(Shipments::class);
    $shipment = $shipments->fromPickList($test->owner, $test->company, $list->fresh());
    $shipments->packRemaining($test->owner, $test->company, $shipment);
    $shipments->ship($test->owner, $test->company, $shipment, 'DHL', 'TRK-9', CarbonImmutable::parse($shippedOn));

    return [$customer, $shipment->fresh()];
}

it('only corrects tracking on a sent shipment', function () {
    Inventory::receive($this->company, $this->owner, $this->plain, $this->places['bin1'], '5', '10');
    $list = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->plain->id, 'quantity' => '1']]);
    app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-04-02'));
    $shipments = app(Shipments::class);
    $shipment = $shipments->fromPickList($this->owner, $this->company, $list->fresh());

    expect(fn () => $shipments->setTracking($this->owner, $this->company, $shipment, 'DHL', '1'))->toThrow(ValidationException::class);

    $shipments->cancel($this->owner, $this->company, $shipment);
    expect(fn () => $shipments->setTracking($this->owner, $this->company, $shipment->fresh(), 'DHL', '1'))->toThrow(ValidationException::class)
        ->and($shipment->fresh()->tracking_number)->toBeNull();
});

it('refuses a ship date in the future or before the goods were picked, and a bad date on the page', function () {
    Inventory::receive($this->company, $this->owner, $this->plain, $this->places['bin1'], '5', '10');
    $list = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->plain->id, 'quantity' => '1']]);
    app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-04-02'));
    $shipments = app(Shipments::class);
    $shipment = $shipments->fromPickList($this->owner, $this->company, $list->fresh());
    $shipments->packRemaining($this->owner, $this->company, $shipment);

    expect(fn () => $shipments->ship($this->owner, $this->company, $shipment, '', '', CarbonImmutable::now()->addDays(3)))->toThrow(ValidationException::class)
        ->and(fn () => $shipments->ship($this->owner, $this->company, $shipment, '', '', CarbonImmutable::parse('2026-04-01')))->toThrow(ValidationException::class);

    Livewire::withQueryParams(['forsandelse' => $shipment->id])->test(ShipmentsPage::class)
        ->set('date', 'inte ett datum')->call('ship')->assertHasErrors(['date'])
        ->set('date', '2026-02-31')->call('ship')->assertHasErrors(['date']);

    expect($shipment->fresh()->status)->toBe(Shipment::DRAFT);
});

it('empties recipient data seven calendar years after shipping and deletes old cancelled shipments', function () {
    [, $old] = e14pShipped($this, '2026-04-03');
    expect($old->ship_to)->not->toBeNull();

    $this->artisan('inventory:prune-shipments')->assertSuccessful();
    expect($old->fresh()->ship_to)->not->toBeNull();

    // Shipped in 2026: kept to the end of 2033, emptied from 1 January 2034.
    $this->travelTo(CarbonImmutable::parse('2033-12-31 12:00'));
    $this->artisan('inventory:prune-shipments')->assertSuccessful();
    expect($old->fresh()->ship_to)->not->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2034-01-02 12:00'));
    $this->artisan('inventory:prune-shipments')->assertSuccessful();
    $emptied = $old->fresh();
    expect($emptied->ship_to)->toBeNull()->and($emptied->carrier)->toBeNull()->and($emptied->tracking_number)->toBeNull()
        ->and($emptied->status)->toBe(Shipment::SHIPPED)
        ->and(ShipmentPackageLine::query()->count())->toBe(1);
    $this->travelBack();

    Inventory::receive($this->company, $this->owner, $this->plain, $this->places['bin1'], '5', '10');
    $list = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->plain->id, 'quantity' => '1']]);
    app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-04-02'));
    $cancelled = app(Shipments::class)->fromPickList($this->owner, $this->company, $list->fresh());
    app(Shipments::class)->cancel($this->owner, $this->company, $cancelled);

    $this->travel(60)->days();
    $this->artisan('inventory:prune-shipments')->assertSuccessful();
    expect(Shipment::query()->whereKey($cancelled->id)->exists())->toBeTrue();

    $this->travel(40)->days();
    $this->artisan('inventory:prune-shipments')->assertSuccessful();
    expect(Shipment::query()->whereKey($cancelled->id)->exists())->toBeFalse()
        ->and(Shipment::query()->whereKey($old->id)->exists())->toBeTrue();
    $this->travelBack();
});

it('never hard-deletes a customer with only a shipment, and empties the recipient copy', function () {
    [$customer, $shipment] = e14pShipped($this);

    expect(app(EraseRegisterEntry::class)->customer($this->owner, $this->company, $customer))->toBe('anonymised')
        ->and($customer->fresh()->address_line1)->toBeNull()
        ->and($shipment->fresh()->ship_to)->toBeNull()
        ->and($shipment->fresh()->status)->toBe(Shipment::SHIPPED);

    $html = view('inventory::pdf.delivery-note', ['shipment' => $shipment->fresh()->load(['packages.lines.article', 'packages.lines.lot', 'customer', 'pickList', 'salesDocument']), 'company' => $this->company])->render();
    expect($html)->toContain('Mottagare saknas')->not->toContain('Anonymiserad kund');
});

it('includes shipments with packages, batches and serial numbers in the customer extract', function () {
    [$customer] = e14pShipped($this);

    $extract = DataSubjectExport::customer($customer)['forsandelser'];

    expect($extract)->toHaveCount(1)
        ->and($extract[0]['transportor'])->toBe('DHL')
        ->and($extract[0]['sparningsnummer'])->toBe('TRK-9')
        ->and($extract[0]['skickad'])->toBe('2026-04-03')
        ->and($extract[0]['mottagare'])->toContain('Björkvägen 4')
        ->and($extract[0]['kollin'][0]['innehall'][0]['batch_eller_serienummer'])->toStartWith('SN-');
});
