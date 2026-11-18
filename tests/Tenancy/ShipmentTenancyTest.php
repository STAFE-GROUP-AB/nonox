<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\PickLists;
use App\Modules\Inventory\Actions\Shipments;
use App\Modules\Inventory\Livewire\ShipmentsPage;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\ShipmentPackage;
use App\Modules\Inventory\Models\ShipmentPackageLine;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Connect;
use Tests\Support\Inventory;

/*
 * AGENTS.md §13.2 for packing and shipments (ADR-081 E14): company A never
 * sees B's shipments, packages or package lines, on the page or in the
 * delivery note; B's ids answer 404 and nothing of B's changes.
 */

beforeEach(function () {
    Mail::fake();
    Connect::setUp();

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Connect::company();
        $this->actingAs($owner);
        Inventory::enable($company, $owner);
        $places = Inventory::locations($company, $owner);
        $article = Inventory::article($company, $owner, "{$name}-1", "{$name}vara");
        Inventory::receive($company, $owner, $article, $places['bin1'], '3', '50');
        $pick = app(PickLists::class)->manual($owner, $company, [['article_id' => $article->id, 'quantity' => '2']]);
        app(PickLists::class)->complete($owner, $company, $pick, CarbonImmutable::parse('2026-04-02'));
        $shipment = app(Shipments::class)->fromPickList($owner, $company, $pick->fresh());
        $package = app(Shipments::class)->packRemaining($owner, $company, $shipment);
        app(Shipments::class)->updatePackage($owner, $company, $package, ['weight' => '2']);
        $this->{$side} = compact('owner', 'company', 'article', 'shipment', 'package');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes shipments, packages and package lines to the current company', function (string $model) {
    expect($model::query()->count())->toBeGreaterThan(0)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([Shipment::class, ShipmentPackage::class, ShipmentPackageLine::class]);

it('answers 404 for the other company\'s shipment on the page and on the delivery note', function () {
    expect(Shipment::query()->find($this->b['shipment']->id))->toBeNull();

    $this->get(route('inventory.shipments.deliveryNote', $this->b['shipment']->id))->assertNotFound();
    $this->get(route('inventory.shipments.deliveryNote', $this->a['shipment']->id))->assertOk();

    Livewire::test(ShipmentsPage::class)->assertSee('Försändelse '.$this->a['shipment']->number)->call('show', $this->b['shipment']->id)->assertNotFound();
    Livewire::withQueryParams(['forsandelse' => $this->b['shipment']->id])->test(ShipmentsPage::class)->assertNotFound();
});

it('changes nothing of the other company\'s shipment', function () {
    $open = fn () => Livewire::withQueryParams(['forsandelse' => $this->a['shipment']->id])->test(ShipmentsPage::class);
    $open()->call('removePackage', $this->b['package']->id)->assertNotFound();
    $open()->call('unpack', ShipmentPackageLine::query()->count() + 9999)->assertNotFound();

    expect(fn () => app(Shipments::class)->cancel($this->a['owner'], $this->a['company'], $this->b['shipment']))->toThrow(ModelNotFoundException::class);

    CurrentCompany::set($this->b['company']);
    expect($this->b['shipment']->fresh()->status)->toBe(Shipment::DRAFT)
        ->and(ShipmentPackage::query()->count())->toBe(1);
});
