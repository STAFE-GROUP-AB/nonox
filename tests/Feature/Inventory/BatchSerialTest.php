<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Payables\SaveSupplier;
use App\Models\Article;
use App\Models\SalesDocument;
use App\Modules\Inventory\Actions\PickLists;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\RecordMovement;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Actions\StockCounts;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Tracking;
use App\Modules\Inventory\Domain\ValuationMethod;
use App\Modules\Inventory\Livewire\LotsPage;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockLotLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\LotTrace;
use App\Modules\Inventory\Support\StockBalanceCheck;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Inventory\Support\StockValuationReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * Batch and serial numbers (ADR-081 E12): tracking per article, lots made on
 * receipt, FEFO on the way out, lot levels next to the article's total, the
 * trace both ways, and the valuation that stays per article (owner decision 12).
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->batch = Inventory::article($this->company, $this->owner, 'B-1', 'Mjölk', ['tracking' => 'BATCH']);
    $this->serial = Inventory::article($this->company, $this->owner, 'S-1', 'Borrmaskin', ['tracking' => 'SERIAL']);
});

/** @param  array<string, mixed>  $row */
function e12Receive(object $test, Article $article, string $quantity, array $row, string $cost = '10', string $date = '2026-03-01', string $place = 'bin1'): void
{
    $receipt = app(SaveReceipt::class)($test->owner, $test->company, ['lines' => [['article_id' => $article->id, 'location_id' => $test->places[$place]->id, 'quantity' => $quantity, 'unit_cost' => $cost]]]);
    app(ReceiveStock::class)($test->owner, $test->company, $receipt, [$receipt->lines()->sole()->id => ['quantity' => $quantity, ...$row]], CarbonImmutable::parse($date));
}

function e12Lot(Article $article, string $code): StockLot
{
    return StockLot::query()->where('article_id', $article->id)->where('code', $code)->firstOrFail();
}

function e12LotLevel(StockLot $lot, object $place): int
{
    return (int) StockLotLevel::query()->where('lot_id', $lot->id)->where('location_id', $place->id)->value('quantity_milli');
}

it('sets tracking on a stocked article only while it has no stock', function () {
    expect($this->batch->tracking)->toBe(Tracking::Batch);

    e12Receive($this, $this->batch, '5', ['lot_code' => 'L1', 'expires_on' => '2027-01-01']);
    $edit = fn (array $input) => app(SaveArticle::class)($this->owner, $this->company, ['number' => 'B-1', 'name' => 'Mjölk', 'unit' => 'st', 'price' => '199,00', 'vat_rate' => 2500, 'is_stock_item' => true, ...$input], $this->batch->fresh());

    expect(fn () => $edit(['tracking' => 'NONE']))->toThrow(ValidationException::class)
        ->and(fn () => Inventory::article($this->company, $this->owner, 'X-1', 'Tjänst', ['is_stock_item' => false, 'tracking' => 'BATCH']))->toThrow(ValidationException::class);
});

it('makes a batch on receipt with its best-before date, supplier and receipt line', function () {
    e12Receive($this, $this->batch, '5', ['lot_code' => ' L1 ', 'expires_on' => '2027-01-01']);
    $lot = e12Lot($this->batch, 'L1');

    expect($lot->kind)->toBe('BATCH')
        ->and($lot->expires_on->toDateString())->toBe('2027-01-01')
        ->and($lot->stock_receipt_line_id)->not->toBeNull()
        ->and(e12LotLevel($lot, $this->places['bin1']))->toBe(5000)
        ->and(StockMovement::query()->where('lot_id', $lot->id)->sole()->quantity_milli)->toBe(5000)
        ->and(Inventory::level($this->batch, $this->places['bin1']))->toBe(5000)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([])
        ->and(StockBalanceCheck::differences($this->company))->toBe([]);

    e12Receive($this, $this->batch, '2', ['lot_code' => 'L1'], date: '2026-03-05');
    expect(StockLot::query()->where('article_id', $this->batch->id)->count())->toBe(1)
        ->and(e12LotLevel($lot, $this->places['bin1']))->toBe(7000);
});

it('refuses goods of a tracked article without a batch or serial number, and a missing best-before date when required', function () {
    expect(fn () => e12Receive($this, $this->batch, '5', []))->toThrow(ValidationException::class)
        ->and(fn () => e12Receive($this, $this->serial, '2', []))->toThrow(ValidationException::class)
        ->and(StockMovement::query()->count())->toBe(0);

    $strict = Inventory::article($this->company, $this->owner, 'B-2', 'Yoghurt', ['tracking' => 'BATCH', 'expiry_required' => true]);
    expect($strict->expiry_required)->toBeTrue()
        ->and(fn () => e12Receive($this, $strict, '3', ['lot_code' => 'Y1']))->toThrow(ValidationException::class);

    e12Receive($this, $strict, '3', ['lot_code' => 'Y1', 'expires_on' => '2027-02-01']);
    expect(e12Lot($strict, 'Y1')->expires_on->toDateString())->toBe('2027-02-01');
});

it('keeps a serial number to one unit in one place', function () {
    e12Receive($this, $this->serial, '3', ['serials' => "SN-1\nSN-2, SN-3"]);

    expect(StockLot::query()->where('article_id', $this->serial->id)->count())->toBe(3)
        ->and(StockLotLevel::query()->pluck('quantity_milli')->all())->toBe([1000, 1000, 1000])
        ->and(Inventory::level($this->serial, $this->places['bin1']))->toBe(3000)
        ->and(fn () => e12Receive($this, $this->serial, '2', ['serials' => 'SN-9'], date: '2026-03-02'))->toThrow(ValidationException::class)
        ->and(fn () => e12Receive($this, $this->serial, '1', ['serials' => 'SN-1'], date: '2026-03-02'))->toThrow(ValidationException::class)
        ->and(fn () => e12Receive($this, $this->serial, '2', ['serials' => "SN-7\nSN-7"], date: '2026-03-02'))->toThrow(ValidationException::class)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([]);
});

it('takes goods out in FEFO order and splits a line over the batches', function () {
    e12Receive($this, $this->batch, '4', ['lot_code' => 'LATE', 'expires_on' => '2027-06-01'], date: '2026-03-01');
    e12Receive($this, $this->batch, '3', ['lot_code' => 'EARLY', 'expires_on' => '2027-01-01'], date: '2026-03-02');
    e12Receive($this, $this->batch, '2', ['lot_code' => 'NODATE'], date: '2026-03-03');

    $movements = app(StockLedger::class)->post($this->company, $this->owner, MovementType::Pick, [
        ['article_id' => $this->batch->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => -5000],
    ], CarbonImmutable::parse('2026-04-01'));

    expect($movements)->toHaveCount(1)
        ->and(StockMovement::query()->with('lot')->where('type', 'PICK')->orderBy('id')->get()->map(fn ($m) => [$m->lot->code, $m->quantity_milli])->all())->toBe([['EARLY', -3000], ['LATE', -2000]])
        ->and(Inventory::level($this->batch, $this->places['bin1']))->toBe(4000)
        ->and(e12LotLevel(e12Lot($this->batch, 'EARLY'), $this->places['bin1']))->toBe(0)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([]);

    expect(fn () => app(StockLedger::class)->post($this->company, $this->owner, MovementType::Pick, [
        ['article_id' => $this->batch->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => -5000],
    ], CarbonImmutable::parse('2026-04-02')))->toThrow(ValidationException::class);
});

it('puts expired batches last when picking but first when scrapping', function () {
    e12Receive($this, $this->batch, '2', ['lot_code' => 'OLD', 'expires_on' => '2026-03-10'], date: '2026-03-01');
    e12Receive($this, $this->batch, '2', ['lot_code' => 'FRESH', 'expires_on' => '2027-01-01'], date: '2026-03-02');

    app(RecordMovement::class)($this->owner, $this->company, ['kind' => 'return_out', 'article_id' => $this->batch->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '1', 'date' => '2026-04-01']);
    expect(e12LotLevel(e12Lot($this->batch, 'FRESH'), $this->places['bin1']))->toBe(1000);

    app(RecordMovement::class)($this->owner, $this->company, ['kind' => 'scrap', 'article_id' => $this->batch->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '2', 'date' => '2026-04-02']);
    expect(e12LotLevel(e12Lot($this->batch, 'OLD'), $this->places['bin1']))->toBe(0)
        ->and(e12LotLevel(e12Lot($this->batch, 'FRESH'), $this->places['bin1']))->toBe(1000)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([]);
});

it('suggests lots on a pick list, lets the picker choose another, and takes that lot out', function () {
    e12Receive($this, $this->batch, '3', ['lot_code' => 'EARLY', 'expires_on' => '2027-01-01'], date: '2026-03-01');
    e12Receive($this, $this->batch, '3', ['lot_code' => 'LATE', 'expires_on' => '2027-06-01'], date: '2026-03-02');

    $list = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->batch->id, 'quantity' => '2']]);
    $line = $list->lines()->sole();
    expect($line->lot_id)->toBe(e12Lot($this->batch, 'EARLY')->id);

    app(PickLists::class)->setLot($this->owner, $this->company, $line, e12Lot($this->batch, 'LATE')->id);
    app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-04-01'));

    expect(e12LotLevel(e12Lot($this->batch, 'LATE'), $this->places['bin1']))->toBe(1000)
        ->and(e12LotLevel(e12Lot($this->batch, 'EARLY'), $this->places['bin1']))->toBe(3000)
        ->and(StockMovement::query()->where('type', 'PICK')->sole()->lot_id)->toBe(e12Lot($this->batch, 'LATE')->id)
        ->and(fn () => app(PickLists::class)->setLot($this->owner, $this->company, $line->fresh(), 999999))->toThrow(ValidationException::class);
});

it('lists each serial number of a picked quantity on its own line', function () {
    e12Receive($this, $this->serial, '3', ['serials' => "SN-1\nSN-2\nSN-3"]);

    $list = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->serial->id, 'quantity' => '2']]);
    expect($list->lines()->pluck('quantity_milli')->all())->toBe([1000, 1000]);

    app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-04-01'));
    expect(StockLotLevel::query()->where('quantity_milli', '>', 0)->count())->toBe(1)
        ->and(Inventory::level($this->serial, $this->places['bin1']))->toBe(1000)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([]);
});

it('moves a tracked article in its lots to the new place', function () {
    e12Receive($this, $this->batch, '3', ['lot_code' => 'EARLY', 'expires_on' => '2027-01-01'], date: '2026-03-01');
    e12Receive($this, $this->batch, '3', ['lot_code' => 'LATE', 'expires_on' => '2027-06-01'], date: '2026-03-02');

    app(RecordMovement::class)($this->owner, $this->company, ['kind' => 'transfer', 'article_id' => $this->batch->id, 'location_id' => $this->places['bin1']->id, 'to_location_id' => $this->places['bin2']->id, 'quantity' => '4', 'date' => '2026-04-01']);

    expect(e12LotLevel(e12Lot($this->batch, 'EARLY'), $this->places['bin2']))->toBe(3000)
        ->and(e12LotLevel(e12Lot($this->batch, 'LATE'), $this->places['bin2']))->toBe(1000)
        ->and(e12LotLevel(e12Lot($this->batch, 'LATE'), $this->places['bin1']))->toBe(2000)
        ->and(Inventory::level($this->batch, $this->places['bin2']))->toBe(4000)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([])
        ->and(StockBalanceCheck::differences($this->company))->toBe([]);

    e12Receive($this, $this->serial, '2', ['serials' => "SN-1\nSN-2"], date: '2026-03-03');
    app(RecordMovement::class)($this->owner, $this->company, ['kind' => 'transfer', 'article_id' => $this->serial->id, 'location_id' => $this->places['bin1']->id, 'to_location_id' => $this->places['bin2']->id, 'quantity' => '1', 'date' => '2026-04-02']);
    expect(StockLotLevel::query()->where('article_id', $this->serial->id)->where('location_id', $this->places['bin2']->id)->where('quantity_milli', '>', 0)->count())->toBe(1)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([]);
});

it('counts a tracked article per lot and posts the difference to that lot', function () {
    e12Receive($this, $this->batch, '5', ['lot_code' => 'L1', 'expires_on' => '2027-01-01']);
    $count = app(StockCounts::class)->start($this->owner, $this->company, null, CarbonImmutable::parse('2026-06-01'));
    $line = $count->lines()->sole();
    expect($line->lot_id)->toBe(e12Lot($this->batch, 'L1')->id)->and($line->system_milli)->toBe(5000);

    app(StockCounts::class)->record($this->owner, $this->company, $line, '4', 'DAMAGED');
    app(StockCounts::class)->addLine($this->owner, $this->company, $count, $this->batch->id, $this->places['bin2']->id, null, 'L2', '2027-05-01');
    $extra = $count->lines()->whereNull('counted_milli')->sole();
    app(StockCounts::class)->record($this->owner, $this->company, $extra, '2', 'NOT_REGISTERED');
    app(StockCounts::class)->complete($this->owner, $this->company, $count);

    expect(e12LotLevel(e12Lot($this->batch, 'L1'), $this->places['bin1']))->toBe(4000)
        ->and(e12LotLevel(e12Lot($this->batch, 'L2'), $this->places['bin2']))->toBe(2000)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([])
        ->and(fn () => app(StockCounts::class)->addLine($this->owner, $this->company, $count, $this->batch->id, $this->places['bin1']->id))->toThrow(ValidationException::class);
});

it('puts returned goods back into the lots the source took them from', function () {
    e12Receive($this, $this->batch, '3', ['lot_code' => 'EARLY', 'expires_on' => '2027-01-01'], date: '2026-03-01');
    e12Receive($this, $this->batch, '3', ['lot_code' => 'LATE', 'expires_on' => '2027-06-01'], date: '2026-03-02');
    $list = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->batch->id, 'quantity' => '4']]);
    app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-04-01'));

    app(StockLedger::class)->post($this->company, $this->owner, MovementType::Return, [
        ['article_id' => $this->batch->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => 2500, 'lots_from' => $list->fresh()],
    ], CarbonImmutable::parse('2026-04-02'));

    expect(e12LotLevel(e12Lot($this->batch, 'EARLY'), $this->places['bin1']))->toBe(2500)
        ->and(e12LotLevel(e12Lot($this->batch, 'LATE'), $this->places['bin1']))->toBe(2000)
        ->and(fn () => app(StockLedger::class)->post($this->company, $this->owner, MovementType::Return, [
            ['article_id' => $this->batch->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => 9000],
        ], CarbonImmutable::parse('2026-04-03'), $list->fresh()))->toThrow(ValidationException::class)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([]);
});

it('does not change the year-end valuation: it stays per article, not per batch', function () {
    $plain = Inventory::article($this->company, $this->owner, 'P-1', 'Mjölk utan spårning');
    $buy = function (Article $article, array $lots) {
        foreach ($lots as $index => [$quantity, $cost, $date]) {
            $row = $article->tracking === Tracking::None ? [] : ['lot_code' => "L{$index}", 'expires_on' => '2028-01-01'];
            e12Receive($this, $article, $quantity, $row, $cost, $date);
        }
    };
    $history = [['10', '10,00', '2026-02-01'], ['10', '14,00', '2026-03-01'], ['5', '20,00', '2026-04-01']];
    $buy($this->batch, $history);
    $buy($plain, $history);

    foreach ([$this->batch, $plain] as $article) {
        app(StockLedger::class)->post($this->company, $this->owner, MovementType::Pick, [['article_id' => $article->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => -12000]], CarbonImmutable::parse('2026-05-01'));
        app(StockLedger::class)->post($this->company, $this->owner, MovementType::Scrap, [['article_id' => $article->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => -1000]], CarbonImmutable::parse('2026-06-01'));
    }

    expect(StockMovement::query()->where('article_id', $this->batch->id)->count())->toBeGreaterThan(StockMovement::query()->where('article_id', $plain->id)->count());

    foreach ([ValuationMethod::Fifo, ValuationMethod::Average] as $method) {
        $report = StockValuationReport::at($this->company, CarbonImmutable::parse('2026-12-31'), $method);
        $lines = collect($report->lines)->keyBy('name');
        expect($lines['Mjölk']['cost_value_ore'])->toBe($lines['Mjölk utan spårning']['cost_value_ore'])
            ->and($lines['Mjölk']['value_ore'])->toBe($lines['Mjölk utan spårning']['value_ore'])
            ->and($lines['Mjölk']['quantity_milli'])->toBe($lines['Mjölk utan spårning']['quantity_milli']);
    }

    // FIFO by hand: 12 + 1 units gone of 10 @10, 10 @14, 5 @20 leaves 7 @14 and 5 @20.
    $fifo = collect(StockValuationReport::at($this->company, CarbonImmutable::parse('2026-12-31'), ValuationMethod::Fifo)->lines)->keyBy('name');
    expect($fifo['Mjölk']['cost_value_ore'])->toBe(7 * 1400 + 5 * 2000);
});

it('traces a batch to its supplier, receipt, orders and customers', function () {
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Mejeri AB', 'payment_terms_days' => 30]);
    $receipt = app(SaveReceipt::class)($this->owner, $this->company, ['supplier_id' => $supplier->id, 'lines' => [['article_id' => $this->batch->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '10', 'unit_cost' => '10']]]);
    app(ReceiveStock::class)($this->owner, $this->company, $receipt, [$receipt->lines()->sole()->id => ['quantity' => '10', 'lot_code' => 'LOT-A', 'expires_on' => '2027-01-01']], CarbonImmutable::parse('2026-03-01'));

    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Butik Ett AB', 'payment_terms_days' => 30]);
    $order = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::ORDER, [
        'customer_id' => $customer->id,
        'document_date' => '2026-04-01',
        'rows' => [['article' => $this->batch->number, 'description' => 'Mjölk', 'quantity' => '4', 'price' => '20', 'vat_rate' => 1200]],
    ]);
    $list = app(PickLists::class)->fromSource($this->owner, $this->company, $order);
    app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-04-02'));

    $trace = LotTrace::of(e12Lot($this->batch, 'LOT-A'));

    expect($trace['in'][0]['supplier'])->toBe('Mejeri AB')
        ->and($trace['in'][0]['label'])->toContain((string) $receipt->number)
        ->and($trace['out'][0]['customer'])->toBe('Butik Ett AB')
        ->and($trace['out'][0]['source'])->toBe("Order {$order->number}")
        ->and($trace['customers'])->toHaveCount(1)
        ->and($trace['customers'][0]['quantity_milli'])->toBe(4000)
        ->and($trace['on_hand'][0]['quantity_milli'])->toBe(6000);

    Livewire::test(LotsPage::class)->assertSee('LOT-A')->call('show', e12Lot($this->batch, 'LOT-A')->id)->assertSee('Butik Ett AB')->assertSee('Mejeri AB');
});

it('runs a fixed number of queries on the batch page whatever the history', function () {
    e12Receive($this, $this->batch, '50', ['lot_code' => 'LOT-A', 'expires_on' => '2027-01-01']);
    $lot = e12Lot($this->batch, 'LOT-A');
    $count = function () use ($lot): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(LotsPage::class)->call('show', $lot->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $short = $count();

    for ($i = 1; $i <= 6; $i++) {
        $list = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->batch->id, 'quantity' => '2']]);
        app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-04-0'.$i));
        e12Receive($this, $this->batch, '1', ['lot_code' => "X{$i}"], date: '2026-05-0'.$i);
    }

    expect(PickList::query()->count())->toBe(6)->and($count())->toBe($short);
});

it('keeps untracked articles untouched and refuses a lot on them', function () {
    $plain = Inventory::article($this->company, $this->owner, 'P-1', 'Kaffekopp');
    Inventory::receive($this->company, $this->owner, $plain, $this->places['bin1'], '5', '10');

    expect(StockMovement::query()->whereNotNull('lot_id')->count())->toBe(0)
        ->and(StockLot::query()->count())->toBe(0)
        ->and(fn () => app(StockLedger::class)->post($this->company, $this->owner, MovementType::Receipt, [
            ['article_id' => $plain->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => 1000, 'lot_code' => 'X'],
        ], CarbonImmutable::parse('2026-04-01')))->toThrow(ValidationException::class);
});

it('refuses a serial number that a parallel posting put in stock first', function () {
    e12Receive($this, $this->serial, '1', ['serials' => 'SN-RACE'], date: '2026-03-01');
    $lot = e12Lot($this->serial, 'SN-RACE');
    $competing = false;

    // The competitor lands a level for the same serial at the other bin while our posting is saving its own.
    StockLotLevel::saving(function (StockLotLevel $level) use (&$competing, $lot): void {
        if ($competing || $level->lot_id !== $lot->id) {
            return;
        }

        $competing = true;
        StockLotLevel::query()->insertOrIgnore(['company_id' => $this->company->id, 'article_id' => $this->serial->id, 'location_id' => $this->places['bin2']->id, 'lot_id' => $lot->id, 'quantity_milli' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    });

    // Take it out, then a second receipt of the same serial: the competing level makes the total 2 units.
    app(StockLedger::class)->post($this->company, $this->owner, MovementType::Scrap, [['article_id' => $this->serial->id, 'location_id' => $this->places['bin1']->id, 'quantity_milli' => -1000]], CarbonImmutable::parse('2026-03-02'));
    StockLotLevel::query()->where('location_id', $this->places['bin2']->id)->delete();
    $competing = false;

    expect(fn () => e12Receive($this, $this->serial, '1', ['serials' => 'SN-RACE'], date: '2026-03-03'))->toThrow(ValidationException::class)
        ->and(StockLotLevel::query()->where('lot_id', $lot->id)->sum('quantity_milli'))->toBe(0)
        ->and(Inventory::level($this->serial, $this->places['bin1']))->toBe(0);
});

it('fails the second pick of the last unit of a lot cleanly', function () {
    e12Receive($this, $this->batch, '1', ['lot_code' => 'LAST', 'expires_on' => '2027-01-01']);
    $first = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->batch->id, 'quantity' => '1']]);
    $second = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->batch->id, 'quantity' => '1']]);

    app(PickLists::class)->complete($this->owner, $this->company, $first, CarbonImmutable::parse('2026-04-01'));

    expect(fn () => app(PickLists::class)->complete($this->owner, $this->company, $second, CarbonImmutable::parse('2026-04-02')))->toThrow(ValidationException::class)
        ->and($second->fresh()->status)->toBe(PickList::OPEN)
        ->and(e12LotLevel(e12Lot($this->batch, 'LAST'), $this->places['bin1']))->toBe(0)
        ->and(StockMovement::query()->where('type', 'PICK')->count())->toBe(1)
        ->and(StockBalanceCheck::lotDifferences($this->company))->toBe([]);
});

it('receives batch and serial numbers from the scanner', function () {
    $scan = fn (array $lines, string $uuid) => $this->postJson(route('inventory.scanner.operations'), [
        'company_id' => $this->company->id,
        'operations' => [['uuid' => $uuid, 'kind' => 'receive', 'payload' => ['receipt_id' => null, 'lines' => $lines]]],
    ]);

    $scan([['article_id' => $this->batch->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '4']], '11111111-1111-4111-8111-111111111111')
        ->assertOk()->assertJsonPath('results.0.status', 'REJECTED');
    expect(StockLot::query()->count())->toBe(0);

    $scan([
        ['article_id' => $this->batch->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '4', 'lot_code' => 'SC-1', 'expires_on' => '2027-05-01'],
        ['article_id' => $this->serial->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '2', 'serials' => ['SC-A', 'SC-B']],
    ], '22222222-2222-4222-8222-222222222222')->assertOk()->assertJsonPath('results.0.status', 'OK');

    expect(e12LotLevel(e12Lot($this->batch, 'SC-1'), $this->places['bin1']))->toBe(4000)
        ->and(e12Lot($this->batch, 'SC-1')->expires_on->toDateString())->toBe('2027-05-01')
        ->and(StockLot::query()->where('article_id', $this->serial->id)->count())->toBe(2);
});
