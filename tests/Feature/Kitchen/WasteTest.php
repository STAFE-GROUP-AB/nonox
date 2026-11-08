<?php

declare(strict_types=1);

use App\Models\Voucher;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Kitchen\Actions\RegisterWaste;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\Kitchen\Livewire\WastePage;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Modules\Kitchen\Models\WasteMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Kitchen;

/*
 * The waste log (ADR-073 §6): an ingredient or a dish written off with SCRAP
 * movements carrying the reason and the entry as source, no voucher, the
 * append-only log corrected by a new entry, plate waste without a
 * movement, and the scanner's Svinn tile exactly once per UUID without a
 * price in its payload.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Kitchen::company();
    $this->actingAs($this->owner);
    $this->fx = Kitchen::fixtures($this->company, $this->owner, $this->places['bin1'], '10');
    $this->bin = $this->places['bin1'];
});

function kitchenScan(array $operations): TestResponse
{
    return test()->postJson(route('inventory.scanner.operations'), ['company_id' => test()->company->id, 'operations' => $operations]);
}

it('writes an ingredient off as SCRAP with its reason and the entry as source, and books nothing', function () {
    $vouchers = Voucher::query()->count();

    $entry = app(RegisterWaste::class)($this->owner, $this->company, [
        'kind' => WasteEntry::INGREDIENT, 'article_id' => $this->fx['articles']['champinjoner']->id, 'quantity' => '1,5',
        'location_id' => $this->bin->id, 'reason' => 'UTGANGET',
    ]);

    $movement = StockMovement::query()->where('source_type', $entry->getMorphClass())->where('source_id', (string) $entry->id)->sole();
    expect($movement)->type->toBe(MovementType::Scrap)->quantity_milli->toBe(-1_500)->reason->toBe('UTGANGET')
        ->and(Inventory::level($this->fx['articles']['champinjoner'], $this->bin))->toBe(8_500)
        ->and(Voucher::query()->count())->toBe($vouchers);
});

it('explodes a dish through its recipe into its stocked ingredients', function () {
    $entry = app(RegisterWaste::class)($this->owner, $this->company, [
        'kind' => WasteEntry::DISH, 'article_id' => $this->fx['articles']['dish']->id, 'quantity' => '2',
        'location_id' => $this->bin->id, 'reason' => 'OVERPRODUKTION',
    ]);

    $a = $this->fx['articles'];
    $out = StockMovement::query()->where('source_id', (string) $entry->id)->where('source_type', $entry->getMorphClass())->pluck('quantity_milli', 'article_id')->all();

    // Two of ten portions: a fifth of each gross quantity; the sub-recipe down to its butter and garlic.
    expect($out)->toEqualCanonicalizing([
        $a['pasta']->id => -200, $a['champinjoner']->id => -267, $a['gradde']->id => -160, $a['parmesan']->id => -52,
        $a['lok']->id => -91, $a['smor']->id => -18, $a['vitlok']->id => -4,
    ]);
});

it('requires the recipient for a donation and a note for Övrigt, and refuses a future date', function () {
    $base = ['kind' => WasteEntry::INGREDIENT, 'article_id' => $this->fx['articles']['lok']->id, 'quantity' => '1', 'location_id' => $this->bin->id];

    expect(fn () => app(RegisterWaste::class)($this->owner, $this->company, [...$base, 'reason' => 'SKANKT']))->toThrow(ValidationException::class, 'organisation');
    expect(fn () => app(RegisterWaste::class)($this->owner, $this->company, [...$base, 'reason' => 'OVRIGT']))->toThrow(ValidationException::class, 'Övrigt');
    expect(fn () => app(RegisterWaste::class)($this->owner, $this->company, [...$base, 'reason' => 'FEL', 'date' => '2026-11-11']))->toThrow(ValidationException::class, 'i förväg');

    $donated = app(RegisterWaste::class)($this->owner, $this->company, [...$base, 'reason' => 'SKANKT', 'recipient' => 'Exempelmatbanken']);
    expect($donated->recipient)->toBe('Exempelmatbanken');
});

it('allows a date in a locked period, since no voucher is made', function () {
    $this->company->forceFill(['locked_until_date' => '2026-10-31'])->save();

    $entry = app(RegisterWaste::class)($this->owner, $this->company->fresh(), [
        'kind' => WasteEntry::INGREDIENT, 'article_id' => $this->fx['articles']['lok']->id, 'quantity' => '0,5', 'location_id' => $this->bin->id,
        'reason' => 'FORDARVAT', 'date' => '2026-10-20',
    ]);

    expect($entry->registered_on->toDateString())->toBe('2026-10-20');
});

it('corrects an entry with a new one that puts the goods back, once', function () {
    $entry = app(RegisterWaste::class)($this->owner, $this->company, [
        'kind' => WasteEntry::INGREDIENT, 'article_id' => $this->fx['articles']['lok']->id, 'quantity' => '2', 'location_id' => $this->bin->id, 'reason' => 'FEL',
    ]);

    $correction = app(RegisterWaste::class)->correct($this->owner, $this->company, $entry);

    expect($correction)->quantity_milli->toBe(-2_000)->corrects_entry_id->toBe($entry->id)
        ->and(Inventory::level($this->fx['articles']['lok'], $this->bin))->toBe(10_000)
        ->and(StockMovement::query()->where('source_id', (string) $correction->id)->where('source_type', $correction->getMorphClass())->sole()->type)->toBe(MovementType::Return);

    expect(fn () => app(RegisterWaste::class)->correct($this->owner, $this->company, $entry))->toThrow(ValidationException::class, 'redan rättad');
    expect(fn () => $entry->update(['quantity_milli' => 1]))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('records plate waste in kg with no stock movement', function () {
    $movements = StockMovement::query()->count();

    $measurement = app(RegisterWaste::class)->plate($this->owner, $this->company, '3,25');

    expect($measurement)->toBeInstanceOf(WasteMeasurement::class)->kg_milli->toBe(3_250)
        ->and(StockMovement::query()->count())->toBe($movements);
});

it('registers from the page with a Swedish toast and corrects after a confirmation', function () {
    Livewire::test(WastePage::class)
        ->set('form.article_id', (string) $this->fx['articles']['parmesan']->id)
        ->set('form.quantity', '0,2')
        ->set('form.location_id', (string) $this->bin->id)
        ->set('form.reason', 'UTGANGET')
        ->call('register')
        ->assertDispatched('toast', message: 'Svinnet är registrerat och draget från lagret.')
        ->assertSee('Parmesan')
        ->call('correct', WasteEntry::query()->sole()->id)
        ->assertDispatched('toast', message: 'Raden är rättad och varorna är tillbaka i lagret.');
});

it('runs a waste operation from the scanner exactly once per UUID, for Kökspersonal too', function () {
    $staff = Bookkeeping::member($this->company, KitchenModule::ROLE);
    $this->company->unsetRelation('users');
    $this->actingAs($staff);

    $operation = ['uuid' => (string) Str::uuid(), 'kind' => 'waste', 'payload' => [
        'kind' => 'DISH', 'article_id' => $this->fx['articles']['dish']->id, 'quantity' => '1', 'location_id' => $this->bin->id, 'reason' => 'OVERPRODUKTION',
    ]];

    $first = kitchenScan([$operation])->assertOk()->json('results.0');
    $again = kitchenScan([$operation])->assertOk()->json('results.0');

    expect($first['status'])->toBe('OK')
        ->and($again['replayed'] ?? false)->toBeTrue()
        ->and(WasteEntry::query()->count())->toBe(1)
        ->and(json_encode($first))->not->toContain('cost')->not->toContain('price');

    // Kökspersonal cannot run Lager's own kinds.
    kitchenScan([['uuid' => (string) Str::uuid(), 'kind' => 'move', 'payload' => []]])->assertForbidden();
});

it('rejects a scanner waste operation with the reason, without throwing the queue away', function () {
    $result = kitchenScan([['uuid' => (string) Str::uuid(), 'kind' => 'waste', 'payload' => [
        'kind' => 'INGREDIENT', 'article_id' => $this->fx['articles']['lok']->id, 'quantity' => '1', 'location_id' => $this->bin->id, 'reason' => '',
    ]]])->assertOk()->json('results.0');

    expect($result['status'])->toBe('REJECTED')->and($result['message'])->toBe('Välj varför det slängs.');
});

it('puts corrected goods back at the cost they left at, not a newer purchase price (review P3.6)', function () {
    $entry = app(RegisterWaste::class)($this->owner, $this->company, [
        'kind' => WasteEntry::INGREDIENT, 'article_id' => $this->fx['articles']['lok']->id, 'quantity' => '2', 'location_id' => $this->bin->id, 'reason' => 'FEL',
    ]);
    Inventory::receive($this->company, $this->owner, $this->fx['articles']['lok'], $this->bin, '10', '30', '2026-11-10');

    $correction = app(RegisterWaste::class)->correct($this->owner, $this->company, $entry);

    expect(StockMovement::query()->where('source_id', (string) $correction->id)->where('source_type', $correction->getMorphClass())->sole()->unit_cost_ore)->toBe(1_500);
});

it('shows the cost value and the Tax Agency note for a donation (review P3.3)', function () {
    Livewire::test(WastePage::class)
        ->set('form.article_id', (string) $this->fx['articles']['lok']->id)
        ->set('form.quantity', '2')
        ->set('form.reason', 'SKANKT')
        ->assertSee('Skatteverket anser att skänkt mat')
        ->assertSee('Kostnadsvärde: 30,00');
});
