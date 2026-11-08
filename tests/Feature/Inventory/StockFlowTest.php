<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Modules\Inventory\Actions\PickLists;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\RecordMovement;
use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Actions\StockCounts;
use App\Modules\Inventory\Events\StockChanged;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Support\StockBalanceCheck;
use App\Modules\ModuleDisabledException;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * The stock ledger end to end (ADR-039): receipts (in parts), moves, pick
 * lists sorted by location, counts with reasons, negative stock, the
 * append-only ledger and the balance check.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->article = Inventory::article($this->company, $this->owner);
});

it('builds location codes from the tree and carries a new code down', function () {
    expect($this->places['bin1']->full_code)->toBe('HL-A-01')
        ->and($this->places['warehouse']->city)->toBe('Stockholm')
        ->and($this->places['bin1']->address_line1)->toBeNull();

    app(SaveLocation::class)($this->owner, $this->company, ['code' => 'B'], $this->places['zone']);
    expect($this->places['bin2']->fresh()->full_code)->toBe('HL-B-02');

    expect(fn () => app(SaveLocation::class)($this->owner, $this->company, ['type' => 'ZONE', 'parent_id' => $this->places['bin1']->id, 'code' => 'X']))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveLocation::class)($this->owner, $this->company, ['type' => 'BIN', 'code' => 'X']))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveLocation::class)($this->owner, $this->company, ['type' => 'BIN', 'parent_id' => $this->places['zone']->id, 'code' => '01']))->toThrow(ValidationException::class);
});

it('receives in parts, keeps the balance equal to the ledger, and announces the change', function () {
    Event::fake([StockChanged::class]);

    $receipt = app(SaveReceipt::class)($this->owner, $this->company, ['lines' => [
        ['article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '10', 'unit_cost' => '75,50'],
    ]]);
    $line = $receipt->lines()->sole();

    app(ReceiveStock::class)($this->owner, $this->company, $receipt, [$line->id => ['quantity' => '4']], CarbonImmutable::parse('2026-03-01'));
    expect($receipt->fresh()->status)->toBe(StockReceipt::PARTIAL)->and(Inventory::level($this->article, $this->places['bin1']))->toBe(4000);

    app(ReceiveStock::class)->all($this->owner, $this->company, $receipt->fresh(), CarbonImmutable::parse('2026-03-02'));
    expect($receipt->fresh()->status)->toBe(StockReceipt::RECEIVED)
        ->and(Inventory::level($this->article, $this->places['bin1']))->toBe(10000)
        ->and(StockMovement::query()->pluck('unit_cost_ore')->all())->toBe([7550, 7550])
        ->and(StockBalanceCheck::differences($this->company))->toBe([]);

    Event::assertDispatched(StockChanged::class, 2);
    expect(fn () => app(ReceiveStock::class)->all($this->owner, $this->company, $receipt->fresh(), CarbonImmutable::today()))->toThrow(ValidationException::class);
});

it('picks an order across locations in walking order and takes it out of stock', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin2'], '3', '80');
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '2', '80');
    $other = Inventory::article($this->company, $this->owner, 'V-2', 'Tefat');
    Inventory::receive($this->company, $this->owner, $other, $this->places['bin1'], '5', '20');

    $list = app(PickLists::class)->manual($this->owner, $this->company, [
        ['article_id' => $this->article->id, 'quantity' => '4'],
        ['article_id' => $other->id, 'quantity' => '1'],
    ]);

    $lines = $list->lines()->with('location')->get();
    expect($lines->map(fn ($line) => [$line->location?->full_code, $line->article_id, $line->quantity_milli])->all())->toBe([
        ['HL-A-01', $this->article->id, 2000],
        ['HL-A-01', $other->id, 1000],
        ['HL-A-02', $this->article->id, 2000],
    ]);

    // Only one of two found on A-02: picked 1.
    app(PickLists::class)->pick($this->owner, $this->company, $lines[2], '1');
    app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-04-01'));

    expect($list->fresh()->status)->toBe(PickList::COMPLETED)
        ->and(Inventory::level($this->article, $this->places['bin1']))->toBe(0)
        ->and(Inventory::level($this->article, $this->places['bin2']))->toBe(2000)
        ->and(Inventory::level($other, $this->places['bin1']))->toBe(4000)
        ->and(StockBalanceCheck::differences($this->company))->toBe([]);
});

it('makes a pick list from a customer order', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '80');
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kund AB', 'payment_terms_days' => 30]);
    $order = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::ORDER, [
        'customer_id' => $customer->id,
        'document_date' => '2026-04-01',
        'rows' => [
            ['article' => $this->article->number, 'description' => 'Kaffekopp', 'quantity' => '3', 'price' => '199', 'vat_rate' => 2500],
            ['description' => 'Frakt', 'quantity' => '1', 'price' => '49', 'vat_rate' => 2500],
        ],
    ]);

    $list = app(PickLists::class)->fromSource($this->owner, $this->company, $order);
    expect($list->source_label)->toBe("Order {$order->number}")
        ->and($list->customer_id)->toBe($customer->id)
        ->and($list->lines()->sole()->quantity_milli)->toBe(3000);

    expect(fn () => app(PickLists::class)->fromSource($this->owner, $this->company, $order))->toThrow(ValidationException::class);
});

it('refuses to go below zero unless the company allows it', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '2', '80');
    $scrap = ['kind' => 'scrap', 'article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '3', 'date' => '2026-05-01'];

    expect(fn () => app(RecordMovement::class)($this->owner, $this->company, $scrap))->toThrow(ValidationException::class);
    expect(Inventory::level($this->article, $this->places['bin1']))->toBe(2000);

    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $this->company, $this->owner, ['allow_negative_stock' => true]);
    app(RecordMovement::class)($this->owner, $this->company, $scrap);
    expect(Inventory::level($this->article, $this->places['bin1']))->toBe(-1000);
});

it('moves between locations without changing the total', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '5', '80');

    $moves = app(RecordMovement::class)($this->owner, $this->company, ['kind' => 'transfer', 'article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'to_location_id' => $this->places['bin2']->id, 'quantity' => '2,5', 'date' => '2026-05-01']);

    expect($moves)->toHaveCount(2)
        ->and($moves[0]->transfer_group)->toBe($moves[1]->transfer_group)
        ->and(Inventory::level($this->article, $this->places['bin1']))->toBe(2500)
        ->and(Inventory::level($this->article, $this->places['bin2']))->toBe(2500);
});

it('counts a location and books the differences with their reasons', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '10', '80');
    $other = Inventory::article($this->company, $this->owner, 'V-2', 'Tefat');

    $count = app(StockCounts::class)->start($this->owner, $this->company, $this->places['zone']->id, CarbonImmutable::parse('2026-12-31'));
    $line = $count->lines()->sole();
    expect($line->system_milli)->toBe(10000);

    app(StockCounts::class)->record($this->owner, $this->company, $line, '8');
    $found = app(StockCounts::class)->addLine($this->owner, $this->company, $count, $other->id, $this->places['bin2']->id);
    app(StockCounts::class)->record($this->owner, $this->company, $found, '3', 'NOT_REGISTERED');

    // A difference needs a reason.
    expect(fn () => app(StockCounts::class)->complete($this->owner, $this->company, $count))->toThrow(ValidationException::class);

    app(StockCounts::class)->record($this->owner, $this->company, $line->fresh(), '8', 'THEFT');
    app(StockCounts::class)->complete($this->owner, $this->company, $count->fresh());

    expect(Inventory::level($this->article, $this->places['bin1']))->toBe(8000)
        ->and(Inventory::level($other, $this->places['bin2']))->toBe(3000)
        ->and(StockMovement::query()->where('type', 'COUNT')->orderBy('id')->get(['quantity_milli', 'reason', 'unit_cost_ore'])->toArray())->toBe([
            ['quantity_milli' => -2000, 'reason' => 'THEFT', 'unit_cost_ore' => null],
            ['quantity_milli' => 3000, 'reason' => 'NOT_REGISTERED', 'unit_cost_ore' => 8000],
        ])
        ->and(StockBalanceCheck::differences($this->company))->toBe([]);

    expect(fn () => app(StockCounts::class)->record($this->owner, $this->company, StockCountLine::query()->findOrFail($found->id), '1'))->toThrow(ValidationException::class);
});

it('keeps the stock right after receipt, pick and count together', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '12', '80');
    $list = app(PickLists::class)->manual($this->owner, $this->company, [['article_id' => $this->article->id, 'quantity' => '5']]);
    app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-06-01'));

    $count = app(StockCounts::class)->start($this->owner, $this->company, null, CarbonImmutable::parse('2026-06-30'));
    app(StockCounts::class)->record($this->owner, $this->company, $count->lines()->sole(), '7');
    app(StockCounts::class)->complete($this->owner, $this->company, $count);

    expect(Inventory::level($this->article, $this->places['bin1']))->toBe(7000)
        ->and((int) StockMovement::query()->sum('quantity_milli'))->toBe(7000)
        ->and(StockMovement::query()->where('type', 'COUNT')->count())->toBe(0);

    $this->artisan('inventory:verify', ['company' => $this->company->id])->assertSuccessful();
});

it('never changes or removes a movement, through Eloquent, the query builder or SQL', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '1', '80');
    $movement = StockMovement::query()->sole();

    expect(fn () => $movement->update(['quantity_milli' => 5000]))->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class);

    foreach ([
        fn () => DB::table('stock_movements')->where('id', $movement->id)->update(['quantity_milli' => 5000]),
        fn () => DB::table('stock_movements')->where('id', $movement->id)->delete(),
        fn () => DB::statement('UPDATE stock_movements SET note = ? WHERE id = ?', ['x', $movement->id]),
    ] as $attempt) {
        DB::beginTransaction();

        try {
            // The trigger refuses; with the database roles (ADR-079 §6) the grant refuses first.
            expect($attempt)->toThrow(fn (QueryException $exception) => expect($exception->getMessage())->toMatch('/append-only|permission denied for table stock_movements/'));
        } finally {
            DB::rollBack();
        }
    }

    expect($movement->fresh()->quantity_milli)->toBe(1000);
});

it('reports a balance that does not match the ledger', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '4', '80');
    DB::table('stock_levels')->update(['quantity_milli' => 3000]);

    expect(StockBalanceCheck::differences($this->company))->toHaveCount(1);
    $this->artisan('inventory:verify', ['company' => $this->company->id])->assertFailed();
});

it('lets only those who handle goods move them, and nobody while the module is off', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $this->company->unsetRelation('users');
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '4', '80');
    $scrap = ['kind' => 'scrap', 'article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '1', 'date' => '2026-05-01'];

    expect(fn () => app(RecordMovement::class)($auditor, $this->company, $scrap))->toThrow(AuthorizationException::class);

    app(RecordMovement::class)($clerk, $this->company, $scrap);
    expect(fn () => app(SaveLocation::class)($clerk, $this->company, ['type' => 'WAREHOUSE', 'code' => 'X']))->toThrow(AuthorizationException::class);

    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, confirmed: true);
    expect(fn () => app(RecordMovement::class)($this->owner, $this->company, $scrap))->toThrow(ModuleDisabledException::class);
});

it('uses the article purchase price as the cost when someone without prices receives', function () {
    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $this->company->unsetRelation('users');
    $receipt = app(SaveReceipt::class)($clerk, $this->company, ['lines' => [['article_id' => $this->article->id, 'location_id' => $this->places['bin1']->id, 'quantity' => '2', 'unit_cost' => '1,00']]]);

    expect($receipt->lines()->sole()->unit_cost_ore)->toBe(8000);
    expect(fn () => app(SaveReceipt::class)($clerk, $this->company, ['lines' => [['article_id' => Inventory::article($this->company, $this->owner, 'T-1', 'Tjänst', ['is_stock_item' => false])->id, 'quantity' => '1']]]))->toThrow(ValidationException::class);
    expect(Customer::query()->count())->toBe(0);
});
