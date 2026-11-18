<?php

declare(strict_types=1);

use App\Models\Article;
use App\Models\Company;
use App\Models\CostCenter;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\RecordMovement;
use App\Modules\Inventory\Actions\ReorderPoints;
use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Actions\StockCounts;
use App\Modules\Inventory\Actions\StockTransfers;
use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\Livewire\LocationsPage;
use App\Modules\Inventory\Livewire\ReorderPointsPanel;
use App\Modules\Inventory\Livewire\StockTransfersPage;
use App\Modules\Inventory\Models\ArticleLocationSetting;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockLotLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Purchasing\PurchaseDemandSource;
use App\Modules\Inventory\Purchasing\PurchaseDemandSources;
use App\Modules\Inventory\Purchasing\PurchaseProposal;
use App\Modules\Inventory\Scanner\ScanCode;
use App\Modules\Inventory\Support\StockBalanceCheck;
use App\Modules\Inventory\Support\StockValuationReport;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * Transfer orders and reorder points per warehouse (ADR-081 E13): goods go
 * from one warehouse to another through a transit location, carry their batch
 * or serial numbers, and are valued exactly as before the whole way; the
 * purchase proposal judges an article warehouse by warehouse once it has
 * reorder points per warehouse.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->shop = app(SaveLocation::class)($this->owner, $this->company, ['type' => 'WAREHOUSE', 'code' => 'BU', 'name' => 'Butiken', 'address_line1' => 'Storgatan 2', 'postal_code' => '111 22', 'city' => 'Stockholm']);
    $this->article = Inventory::article($this->company, $this->owner, 'T-1', 'Skruv');
    $this->batch = Inventory::article($this->company, $this->owner, 'TB-1', 'Mjölk', ['tracking' => 'BATCH']);
    $this->serial = Inventory::article($this->company, $this->owner, 'TS-1', 'Borrmaskin', ['tracking' => 'SERIAL']);
});

/** @param  list<array<string, mixed>>  $lines */
function e13Draft(object $test, array $lines, ?StockLocation $from = null, ?StockLocation $to = null): StockTransfer
{
    return app(StockTransfers::class)->save($test->owner, $test->company, [
        'from_location_id' => ($from ?? $test->places['bin1'])->id,
        'to_location_id' => ($to ?? $test->shop)->id,
        'lines' => $lines,
    ]);
}

function e13Receive(object $test, Article $article, string $quantity, array $row, string $cost = '10', string $date = '2026-03-01'): void
{
    $receipt = app(SaveReceipt::class)($test->owner, $test->company, ['lines' => [['article_id' => $article->id, 'location_id' => $test->places['bin1']->id, 'quantity' => $quantity, 'unit_cost' => $cost]]]);
    app(ReceiveStock::class)($test->owner, $test->company, $receipt, [$receipt->lines()->sole()->id => ['quantity' => $quantity, ...$row]], CarbonImmutable::parse($date));
}

function e13Transit(object $test): StockLocation
{
    return StockLocation::query()->where('type', LocationType::Transit->value)->sole();
}

function e13Total(Article $article): int
{
    return (int) DB::table('stock_levels')->where('article_id', $article->id)->sum('quantity_milli');
}

function e13Value(object $test): int
{
    return StockValuationReport::at($test->company, CarbonImmutable::parse('2026-12-31'))->valueOre;
}

it('sends goods into transit and receives them in parts without changing the total or the value', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    $before = e13Value($this);
    $draft = e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]);

    expect($draft->status)->toBe(StockTransfer::DRAFT)
        ->and($draft->number)->toBe(1)
        ->and(Inventory::level($this->article, $this->places['bin1']))->toBe(10000);

    $sent = app(StockTransfers::class)->send($this->owner, $this->company, $draft, CarbonImmutable::parse('2026-04-01'));
    $transit = e13Transit($this);

    expect($sent->status)->toBe(StockTransfer::SENT)
        ->and($sent->transit_location_id)->toBe($transit->id)
        ->and($transit->is_active)->toBeFalse()
        ->and($transit->transit_from_id)->toBe($this->places['warehouse']->id)
        ->and($transit->transit_to_id)->toBe($this->shop->id)
        ->and(Inventory::level($this->article, $this->places['bin1']))->toBe(6000)
        ->and(Inventory::level($this->article, $transit))->toBe(4000)
        ->and(e13Total($this->article))->toBe(10000)
        ->and(e13Value($this))->toBe($before);

    $line = $sent->lines()->sole();
    $partly = app(StockTransfers::class)->receive($this->owner, $this->company, $sent, [$line->id => '3'], CarbonImmutable::parse('2026-04-03'));

    expect($partly->status)->toBe(StockTransfer::PARTIALLY_RECEIVED)
        ->and(Inventory::level($this->article, $this->shop))->toBe(3000)
        ->and(Inventory::level($this->article, $transit))->toBe(1000);

    // Two movements for the send and two for the part received, with the order as their source.
    expect(StockMovement::query()->where('source_type', StockTransfer::class)->where('source_id', (string) $sent->id)->count())->toBe(4);
});

it('puts the goods into the receiving location and closes the order when all has arrived', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    $before = e13Value($this);
    $transfers = app(StockTransfers::class);
    $sent = $transfers->send($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]), CarbonImmutable::parse('2026-04-01'));
    $line = $sent->lines()->sole();

    $transfers->receive($this->owner, $this->company, $sent, [$line->id => '3'], CarbonImmutable::parse('2026-04-03'));
    $done = $transfers->receive($this->owner, $this->company, $sent->fresh(), [$line->id => '1'], CarbonImmutable::parse('2026-04-05'));

    expect($done->status)->toBe(StockTransfer::RECEIVED)
        ->and($done->completed_at)->not->toBeNull()
        ->and(Inventory::level($this->article, $this->shop))->toBe(4000)
        ->and(Inventory::level($this->article, e13Transit($this)))->toBe(0)
        ->and(Inventory::level($this->article, $this->places['bin1']))->toBe(6000)
        ->and(e13Value($this))->toBe($before)
        ->and(StockBalanceCheck::differences($this->company))->toBe([])
        ->and(StockBalanceCheck::transitDifferences($this->company))->toBe([]);

    expect(fn () => $transfers->receive($this->owner, $this->company, $done, [$line->id => '1'], CarbonImmutable::parse('2026-04-06')))->toThrow(ValidationException::class);
});

it('adds no cost on a transfer and leaves the FIFO value alone', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '5', '100');
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '5', '200', '2026-03-05');
    $before = e13Value($this);
    $sent = app(StockTransfers::class)->send($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '7']]), CarbonImmutable::parse('2026-04-01'));

    expect(StockMovement::query()->where('source_id', (string) $sent->id)->where('source_type', StockTransfer::class)->whereNotNull('unit_cost_ore')->count())->toBe(0)
        ->and(e13Value($this))->toBe($before);
});

it('carries the batches in FEFO order and each batch arrives as the batch it was', function () {
    e13Receive($this, $this->batch, '5', ['lot_code' => 'LATE', 'expires_on' => '2027-06-01']);
    e13Receive($this, $this->batch, '3', ['lot_code' => 'SOON', 'expires_on' => '2027-01-01'], date: '2026-03-02');
    $soon = StockLot::query()->where('code', 'SOON')->sole();
    $late = StockLot::query()->where('code', 'LATE')->sole();

    $draft = e13Draft($this, [['article_id' => $this->batch->id, 'quantity' => '5']]);
    expect($draft->lines()->sole()->lot_id)->toBeNull();

    $sent = app(StockTransfers::class)->send($this->owner, $this->company, $draft, CarbonImmutable::parse('2026-04-01'));
    $lines = $sent->lines()->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('lot_id')->all())->toBe([$soon->id, $late->id])
        ->and($lines->pluck('quantity_milli')->all())->toBe([3000, 2000])
        ->and((int) StockLotLevel::query()->where('lot_id', $soon->id)->where('location_id', e13Transit($this)->id)->value('quantity_milli'))->toBe(3000)
        ->and((int) StockLotLevel::query()->where('lot_id', $late->id)->where('location_id', $this->places['bin1']->id)->value('quantity_milli'))->toBe(3000);

    $arrived = app(StockTransfers::class)->receive($this->owner, $this->company, $sent, $lines->mapWithKeys(fn ($line) => [$line->id => (string) ($line->quantity_milli / 1000)])->all(), CarbonImmutable::parse('2026-04-02'));

    expect($arrived->status)->toBe(StockTransfer::RECEIVED)
        ->and((int) StockLotLevel::query()->where('lot_id', $soon->id)->where('location_id', $this->shop->id)->value('quantity_milli'))->toBe(3000)
        ->and((int) StockLotLevel::query()->where('lot_id', $late->id)->where('location_id', $this->shop->id)->value('quantity_milli'))->toBe(2000)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([])
        ->and(StockBalanceCheck::differences($this->company))->toBe([]);
});

it('moves a named batch and a serial number, and keeps a serial number in one place at a time', function () {
    e13Receive($this, $this->batch, '5', ['lot_code' => 'A1']);
    e13Receive($this, $this->batch, '5', ['lot_code' => 'A2'], date: '2026-03-02');
    e13Receive($this, $this->serial, '2', ['serials' => "SN-1\nSN-2"]);
    $a2 = StockLot::query()->where('code', 'A2')->sole();
    $sn1 = StockLot::query()->where('code', 'SN-1')->sole();

    $sent = app(StockTransfers::class)->send($this->owner, $this->company, e13Draft($this, [
        ['article_id' => $this->batch->id, 'quantity' => '2', 'lot_id' => $a2->id],
        ['article_id' => $this->serial->id, 'quantity' => '1', 'lot_id' => $sn1->id],
    ]), CarbonImmutable::parse('2026-04-01'));

    expect((int) StockLotLevel::query()->where('lot_id', $a2->id)->where('location_id', $this->places['bin1']->id)->value('quantity_milli'))->toBe(3000)
        ->and((int) StockLotLevel::query()->where('lot_id', $sn1->id)->where('location_id', e13Transit($this)->id)->value('quantity_milli'))->toBe(1000)
        ->and((int) StockLotLevel::query()->where('lot_id', $sn1->id)->sum('quantity_milli'))->toBe(1000);

    // A serial number is one unit, and a lot of another article is not accepted.
    expect(fn () => e13Draft($this, [['article_id' => $this->serial->id, 'quantity' => '2', 'lot_id' => $sn1->id]]))->toThrow(ValidationException::class)
        ->and(fn () => e13Draft($this, [['article_id' => $this->batch->id, 'quantity' => '1', 'lot_id' => $sn1->id]]))->toThrow(ValidationException::class)
        ->and(fn () => e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '1', 'lot_id' => $sn1->id]]))->toThrow(ValidationException::class);

    $line = $sent->lines()->where('article_id', $this->serial->id)->sole();
    app(StockTransfers::class)->receive($this->owner, $this->company, $sent, [$line->id => '1'], CarbonImmutable::parse('2026-04-02'));

    expect((int) StockLotLevel::query()->where('lot_id', $sn1->id)->where('location_id', $this->shop->id)->value('quantity_milli'))->toBe(1000)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([]);
});

it('refuses to send more than there is, and leaves a failed send as a draft', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '2', '100');
    $draft = e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '5']]);

    expect(fn () => app(StockTransfers::class)->send($this->owner, $this->company, $draft, CarbonImmutable::parse('2026-04-01')))->toThrow(ValidationException::class);

    expect($draft->fresh()->status)->toBe(StockTransfer::DRAFT)
        ->and(StockLocation::query()->where('type', LocationType::Transit->value)->count())->toBe(0)
        ->and(Inventory::level($this->article, $this->places['bin1']))->toBe(2000);
});

it('refuses a transfer inside one warehouse, to a transit place, from an inactive place and of an article not in stock', function () {
    $transfers = app(StockTransfers::class);
    $line = [['article_id' => $this->article->id, 'quantity' => '1']];

    expect(fn () => e13Draft($this, $line, $this->places['bin1'], $this->places['bin2']))->toThrow(ValidationException::class, 'annat lager');

    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '3', '100');
    $transfers->send($this->owner, $this->company, e13Draft($this, $line), CarbonImmutable::parse('2026-04-01'));

    expect(fn () => e13Draft($this, $line, $this->places['bin1'], e13Transit($this)))->toThrow(ValidationException::class);

    $service = Inventory::article($this->company, $this->owner, 'X-1', 'Konsult', ['is_stock_item' => false]);

    expect(fn () => e13Draft($this, [['article_id' => $service->id, 'quantity' => '1']]))->toThrow(ValidationException::class)
        ->and(fn () => e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '0']]))->toThrow(ValidationException::class)
        ->and(fn () => e13Draft($this, [['article_id' => $this->article->id, 'quantity' => 'abc']]))->toThrow(ValidationException::class)
        ->and(fn () => app(StockTransfers::class)->save($this->owner, $this->company, ['from_location_id' => $this->places['bin1']->id, 'to_location_id' => $this->shop->id, 'lines' => []]))->toThrow(ValidationException::class);
});

it('changes a draft but not a sent order, and refuses to receive more than is on its way', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    $transfers = app(StockTransfers::class);
    $draft = e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]);

    $changed = $transfers->save($this->owner, $this->company, ['from_location_id' => $this->places['bin1']->id, 'to_location_id' => $this->shop->id, 'note' => 'Till butiken', 'lines' => [['article_id' => $this->article->id, 'quantity' => '6']]], $draft);

    expect($changed->lines()->sole()->quantity_milli)->toBe(6000)
        ->and($changed->note)->toBe('Till butiken')
        ->and($changed->number)->toBe($draft->number);

    $sent = $transfers->send($this->owner, $this->company, $changed, CarbonImmutable::parse('2026-04-01'));
    $line = $sent->lines()->sole();

    expect(fn () => $transfers->save($this->owner, $this->company, ['from_location_id' => $this->places['bin1']->id, 'to_location_id' => $this->shop->id, 'lines' => [['article_id' => $this->article->id, 'quantity' => '1']]], $sent))->toThrow(ValidationException::class)
        ->and(fn () => $transfers->send($this->owner, $this->company, $sent, CarbonImmutable::parse('2026-04-02')))->toThrow(ValidationException::class)
        ->and(fn () => $transfers->receive($this->owner, $this->company, $sent, [$line->id => '7'], CarbonImmutable::parse('2026-04-02')))->toThrow(ValidationException::class)
        ->and(fn () => $transfers->receive($this->owner, $this->company, $sent, [], CarbonImmutable::parse('2026-04-02')))->toThrow(ValidationException::class);
});

it('cancels a draft without moving anything, and sends what is still on its way back to the sender', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    $before = e13Value($this);
    $transfers = app(StockTransfers::class);

    $draft = $transfers->cancel($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]), CarbonImmutable::parse('2026-04-01'));
    expect($draft->status)->toBe(StockTransfer::CANCELLED)->and(StockMovement::query()->where('type', 'TRANSFER')->count())->toBe(0);

    $sent = $transfers->send($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]), CarbonImmutable::parse('2026-04-01'));
    $line = $sent->lines()->sole();
    $transfers->receive($this->owner, $this->company, $sent, [$line->id => '1'], CarbonImmutable::parse('2026-04-02'));
    $closed = $transfers->cancel($this->owner, $this->company, $sent->fresh(), CarbonImmutable::parse('2026-04-03'));

    expect($closed->status)->toBe(StockTransfer::RECEIVED)
        ->and($line->fresh()->returned_milli)->toBe(3000)
        ->and(Inventory::level($this->article, $this->shop))->toBe(1000)
        ->and(Inventory::level($this->article, $this->places['bin1']))->toBe(9000)
        ->and(Inventory::level($this->article, e13Transit($this)))->toBe(0)
        ->and(e13Value($this))->toBe($before)
        ->and(StockBalanceCheck::transitDifferences($this->company))->toBe([]);

    $nothing = $transfers->send($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '2']]), CarbonImmutable::parse('2026-04-04'));
    expect($transfers->cancel($this->owner, $this->company, $nothing, CarbonImmutable::parse('2026-04-05'))->status)->toBe(StockTransfer::CANCELLED)
        ->and(Inventory::level($this->article, $this->places['bin1']))->toBe(9000);
});

it('reports a transit balance that does not match the open order lines', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    app(StockTransfers::class)->send($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]), CarbonImmutable::parse('2026-04-01'));
    DB::table('stock_transfer_lines')->update(['received_milli' => 1000]);

    expect(StockBalanceCheck::transitDifferences($this->company))->toHaveCount(1);
});

it('lets Lagerpersonal transfer, and refuses those who may only read', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    $this->company->unsetRelation('users');
    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->company->unsetRelation('users');
    $input = ['from_location_id' => $this->places['bin1']->id, 'to_location_id' => $this->shop->id, 'lines' => [['article_id' => $this->article->id, 'quantity' => '1']]];

    expect(app(StockTransfers::class)->save($clerk, $this->company, $input)->status)->toBe(StockTransfer::DRAFT)
        ->and(fn () => app(StockTransfers::class)->save($auditor, $this->company, $input))->toThrow(AuthorizationException::class);
});

it('runs the transfer page: plan, send, receive, and print the document', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');

    $page = Livewire::test(StockTransfersPage::class)
        ->call('create')
        ->set('form.from_location_id', (string) $this->places['bin1']->id)
        ->set('form.to_location_id', (string) $this->shop->id)
        ->set('rows.0.article_id', (string) $this->article->id)
        ->set('rows.0.quantity', '4')
        ->call('save')
        ->assertDispatched('toast', message: 'Flyttordern är sparad som utkast.');

    $transfer = StockTransfer::query()->sole();
    $page->assertSet('open', $transfer->id)->call('send')->assertDispatched('toast', message: 'Flyttordern är skickad. Varorna är på väg.');

    expect($transfer->fresh()->status)->toBe(StockTransfer::SENT);

    $line = $transfer->lines()->sole();
    $page->assertSet("arrived.{$line->id}", '4')->set("arrived.{$line->id}", '4')->call('receive')->assertDispatched('toast', message: 'Mottagningen är sparad.');

    expect($transfer->fresh()->status)->toBe(StockTransfer::RECEIVED);

    $this->get(route('inventory.transfers.pdf', $transfer->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('inventory.transfers'))->assertOk()->assertSee('Flyttorder');
});

it('keeps the form closed until a new order is asked for', function () {
    Livewire::test(StockTransfersPage::class)->assertDontSee('Spara utkast')->call('create')->assertSee('Spara utkast')->call('cancelForm')->assertDontSee('Spara utkast');
});

it('shows the page error and a toast when sending fails', function () {
    $draft = e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]);

    Livewire::test(StockTransfersPage::class)->call('show', $draft->id)->call('send')->assertDispatched('toast');
    expect($draft->fresh()->status)->toBe(StockTransfer::DRAFT);
});

it('hides the transit place from the location list and every picker', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    app(StockTransfers::class)->send($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]), CarbonImmutable::parse('2026-04-01'));

    Livewire::test(LocationsPage::class)->set('show', 'alla')->assertDontSee('TRANSIT-HL-BU');
    expect(StockLocation::query()->where('is_active', true)->pluck('full_code')->all())->not->toContain('TRANSIT-HL-BU');
});

it('saves the cost centre of a warehouse and refuses one that does not exist', function () {
    $center = CostCenter::create(['code' => '10', 'name' => 'Stockholm', 'is_active' => true]);
    $save = app(SaveLocation::class);

    $warehouse = $save($this->owner, $this->company, ['code' => 'HL', 'name' => 'Huvudlager', 'cost_center_id' => $center->id], $this->places['warehouse']);
    expect($warehouse->cost_center_id)->toBe($center->id);

    // Leaving it out leaves it as it is; a shelf takes none.
    expect($save($this->owner, $this->company, ['code' => 'HL', 'is_active' => true], $warehouse)->cost_center_id)->toBe($center->id)
        ->and($save($this->owner, $this->company, ['code' => 'A', 'cost_center_id' => $center->id], $this->places['zone'])->cost_center_id)->toBeNull()
        ->and(fn () => $save($this->owner, $this->company, ['code' => 'HL', 'cost_center_id' => 999999], $warehouse))->toThrow(ValidationException::class)
        ->and($save($this->owner, $this->company, ['code' => 'HL', 'cost_center_id' => ''], $warehouse)->cost_center_id)->toBeNull();

    Livewire::test(LocationsPage::class)->call('edit', $this->places['warehouse']->id)->set('form.cost_center_id', (string) $center->id)->call('save')->assertDispatched('toast', message: 'Lagerstället är sparat.');
    expect($this->places['warehouse']->fresh()->cost_center_id)->toBe($center->id);
});

it('saves a reorder point per warehouse and refuses a shelf, a service and a negative point', function () {
    $points = app(ReorderPoints::class);
    $setting = $points->save($this->owner, $this->company, $this->article, $this->shop->id, '5', '20');

    expect($setting->reorder_point_milli)->toBe(5000)->and($setting->reorder_quantity_milli)->toBe(20000);

    $again = $points->save($this->owner, $this->company, $this->article, $this->shop->id, '6', '');
    expect($again->id)->toBe($setting->id)->and($again->reorder_point_milli)->toBe(6000)->and($again->reorder_quantity_milli)->toBeNull();

    $service = Inventory::article($this->company, $this->owner, 'X-2', 'Konsult', ['is_stock_item' => false]);

    expect(fn () => $points->save($this->owner, $this->company, $this->article, $this->places['bin1']->id, '5', ''))->toThrow(ValidationException::class)
        ->and(fn () => $points->save($this->owner, $this->company, $service, $this->shop->id, '5', ''))->toThrow(ValidationException::class)
        ->and(fn () => $points->save($this->owner, $this->company, $this->article, $this->shop->id, '-1', ''))->toThrow(ValidationException::class)
        ->and(fn () => $points->save($this->owner, $this->company, $this->article, $this->shop->id, '', ''))->toThrow(ValidationException::class);

    $points->delete($this->owner, $this->company, $again);
    expect(ArticleLocationSetting::query()->count())->toBe(0);
});

it('manages reorder points from the article panel', function () {
    Livewire::test(ReorderPointsPanel::class, ['articleId' => $this->article->id])
        ->set('form.warehouse_id', (string) $this->shop->id)
        ->set('form.point', '5')
        ->set('form.quantity', '10')
        ->call('save')
        ->assertDispatched('toast', message: 'Beställningspunkten för lagret är sparad.')
        ->assertSee('Butiken');

    $setting = ArticleLocationSetting::query()->sole();
    Livewire::test(ReorderPointsPanel::class, ['articleId' => $this->article->id])->call('remove', $setting->id)->assertDispatched('toast', message: 'Beställningspunkten för lagret är borttagen.');
    expect(ArticleLocationSetting::query()->count())->toBe(0);
});

it('judges an article warehouse by warehouse in the purchase proposal and counts goods on their way', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '20', '10');
    $points = app(ReorderPoints::class);
    $line = fn () => collect(app(PurchaseProposal::class)->lines($this->company))->first(fn ($proposal) => $proposal->article->id === $this->article->id);

    // The company's own point is satisfied (20 on hand), so nothing is proposed.
    DB::table('articles')->where('id', $this->article->id)->update(['reorder_point_milli' => 5000, 'reorder_quantity_milli' => 10000]);
    expect($line())->toBeNull();

    // The shop has none and wants 5: it is below its point although the company is not.
    $points->save($this->owner, $this->company, $this->article, $this->shop->id, '5', '8');
    $proposal = $line();

    expect($proposal)->not->toBeNull()
        ->and($proposal->belowReorderPoint)->toBeTrue()
        ->and($proposal->proposedMilli)->toBe(8000);

    // Six on their way to the shop count for it: it is above its point of 5 again.
    $sent = app(StockTransfers::class)->send($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '6']]), CarbonImmutable::parse('2026-04-01'));
    expect($line())->toBeNull();

    // Once the point is above what the shop has plus what is coming, it is proposed again.
    $points->save($this->owner, $this->company, $this->article, $this->shop->id, '9', '');
    expect($line()->proposedMilli)->toBe(3000);

    // The main warehouse (14 left) has a point of its own too: judged separately, the sum is proposed.
    $points->save($this->owner, $this->company, $this->article, $this->places['warehouse']->id, '30', '');
    expect($line()->proposedMilli)->toBe(3000 + 16000);

    app(StockTransfers::class)->receive($this->owner, $this->company, $sent, [$sent->lines()->sole()->id => '6'], CarbonImmutable::parse('2026-04-02'));
    expect($line()->proposedMilli)->toBe(3000 + 16000);
});

it('keeps the article point for an article without per-warehouse points', function () {
    $other = Inventory::article($this->company, $this->owner, 'T-9', 'Mutter');
    DB::table('articles')->where('id', $other->id)->update(['reorder_point_milli' => 5000, 'reorder_quantity_milli' => 10000]);
    app(ReorderPoints::class)->save($this->owner, $this->company, $this->article, $this->shop->id, '5', '');

    $lines = collect(app(PurchaseProposal::class)->lines($this->company))->keyBy(fn ($line) => $line->article->id);

    expect($lines->get($other->id)->proposedMilli)->toBe(10000)
        ->and($lines->get($this->article->id)->proposedMilli)->toBe(5000);
});

it('runs the proposal and the transfer page in a fixed number of queries', function () {
    $queries = function (callable $run): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $grow = function (int $from, int $to): void {
        foreach (range($from, $to) as $index) {
            $article = Inventory::article($this->company, $this->owner, "Q-{$index}", "Artikel {$index}", ['reorder_point' => '3']);
            Inventory::receive($this->company, $this->owner, $article, $this->places['bin1'], '5', '10');
            app(ReorderPoints::class)->save($this->owner, $this->company, $article, $this->shop->id, '2', '');
            app(ReorderPoints::class)->save($this->owner, $this->company, $article, $this->places['warehouse']->id, '9', '');
            $sent = app(StockTransfers::class)->send($this->owner, $this->company, e13Draft($this, [['article_id' => $article->id, 'quantity' => '1']]), CarbonImmutable::parse('2026-04-01'));
            app(StockTransfers::class)->receive($this->owner, $this->company, $sent, [$sent->lines()->sole()->id => '1'], CarbonImmutable::parse('2026-04-02'));
        }
    };

    $grow(1, 2);
    Livewire::test(StockTransfersPage::class);
    $proposalShort = $queries(fn () => app(PurchaseProposal::class)->lines($this->company));
    $pageShort = $queries(fn () => Livewire::test(StockTransfersPage::class));
    $grow(3, 12);

    expect($queries(fn () => app(PurchaseProposal::class)->lines($this->company)))->toBe($proposalShort)
        ->and($queries(fn () => Livewire::test(StockTransfersPage::class)))->toBe($pageShort);
});

it('opens an order in a fixed number of queries whatever the number of lines', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '50', '10');
    $queries = function (int $id): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(StockTransfersPage::class)->call('show', $id);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    Livewire::test(StockTransfersPage::class);
    $short = e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '1']]);
    $long = e13Draft($this, array_map(fn (int $index): array => ['article_id' => $this->article->id, 'quantity' => '1'], range(1, 15)));

    expect($queries($long->id))->toBe($queries($short->id));
});

it('lets only a transfer order touch the transit place', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    app(StockTransfers::class)->send($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]), CarbonImmutable::parse('2026-04-01'));
    $transit = e13Transit($this);
    $move = app(RecordMovement::class);
    $input = fn (array $extra) => ['article_id' => $this->article->id, 'quantity' => '1', 'date' => '2026-04-02', ...$extra];

    expect(fn () => $move($this->owner, $this->company, $input(['kind' => 'scrap', 'location_id' => $transit->id])))->toThrow(ValidationException::class, 'bara av flyttorder')
        ->and(fn () => $move($this->owner, $this->company, $input(['kind' => 'return_in', 'location_id' => $transit->id])))->toThrow(ValidationException::class, 'bara av flyttorder')
        ->and(fn () => $move($this->owner, $this->company, $input(['kind' => 'transfer', 'location_id' => $this->places['bin1']->id, 'to_location_id' => $transit->id])))->toThrow(ValidationException::class, 'bara av flyttorder')
        ->and(fn () => $move($this->owner, $this->company, $input(['kind' => 'transfer', 'location_id' => $transit->id, 'to_location_id' => $this->places['bin2']->id])))->toThrow(ValidationException::class, 'bara av flyttorder')
        ->and(Inventory::level($this->article, $transit))->toBe(4000);
});

it('leaves the transit place out of counts and the scanner', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    app(StockTransfers::class)->send($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]), CarbonImmutable::parse('2026-04-01'));
    $transit = e13Transit($this);

    $count = app(StockCounts::class)->start($this->owner, $this->company, null, CarbonImmutable::parse('2026-04-02'));
    expect($count->lines()->pluck('location_id')->all())->toBe([$this->places['bin1']->id]);

    expect(ScanCode::resolve('P:'.$transit->full_code)['type'])->toBeNull();

    $stock = $this->getJson(route('inventory.scanner.lookup', ['kod' => 'A:'.$this->article->number]));
    expect($stock->getContent())->not->toContain('TRANSIT-')->and($stock->json('stock'))->toHaveCount(1);
});

it('does not deactivate or change a place that a sent order points at, nor the transit place', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    $sent = app(StockTransfers::class)->send($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]), CarbonImmutable::parse('2026-04-01'));
    $save = app(SaveLocation::class);

    expect(fn () => $save($this->owner, $this->company, ['code' => 'BU', 'is_active' => false], $this->shop))->toThrow(ValidationException::class, 'på väg')
        ->and(fn () => $save($this->owner, $this->company, ['code' => 'X'], e13Transit($this)))->toThrow(ValidationException::class);

    Livewire::test(LocationsPage::class)->call('edit', e13Transit($this)->id)->assertNotFound();

    app(StockTransfers::class)->cancel($this->owner, $this->company, $sent, CarbonImmutable::parse('2026-04-02'));
    expect($save($this->owner, $this->company, ['code' => 'BU', 'is_active' => false], $this->shop->fresh())->is_active)->toBeFalse();
});

it('renames the transit place when a warehouse is renamed', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    app(StockTransfers::class)->send($this->owner, $this->company, e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]), CarbonImmutable::parse('2026-04-01'));

    app(SaveLocation::class)($this->owner, $this->company, ['code' => 'BX'], $this->shop->fresh());

    expect(e13Transit($this)->full_code)->toBe('TRANSIT-HL-BX');
});

it('shows an error and a toast for a date that is not a date, never a server error', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '100');
    $draft = e13Draft($this, [['article_id' => $this->article->id, 'quantity' => '4']]);

    Livewire::test(StockTransfersPage::class)->call('show', $draft->id)->set('date', 'inte ett datum')->call('send')->assertDispatched('toast')->assertHasErrors('date');
    expect($draft->fresh()->status)->toBe(StockTransfer::DRAFT);
});

it('counts reserved goods in the per-warehouse proposal as the company-wide path does', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '20', '10');
    app(ReorderPoints::class)->save($this->owner, $this->company, $this->article, $this->shop->id, '5', '8');
    $id = $this->article->id;
    app(PurchaseDemandSources::class)->register(new class($id) implements PurchaseDemandSource
    {
        public function __construct(private readonly int $articleId) {}

        public function label(): string
        {
            return 'Test';
        }

        public function reserved(Company $company): array
        {
            return [$this->articleId => 3000];
        }
    });

    $line = collect(app(PurchaseProposal::class)->lines($this->company))->first(fn ($proposal) => $proposal->article->id === $id);

    // The shop has 0 of 5: its refill is the reorder quantity 8; the 3 reserved are added.
    expect($line->proposedMilli)->toBe(11000);
});
