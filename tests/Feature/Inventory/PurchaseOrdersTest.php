<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Models\Voucher;
use App\Modules\Inventory\Actions\PickLists;
use App\Modules\Inventory\Actions\Purchasing\CreatePurchaseOrdersFromProposal;
use App\Modules\Inventory\Actions\Purchasing\MatchSupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\PurchaseOrderLifecycle;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseOrder;
use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Domain\PurchaseOrderSource;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Mail\PurchaseOrderMail;
use App\Modules\Inventory\Models\PurchaseOrderVersion;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Purchasing\PurchaseOrderDocument;
use App\Modules\Inventory\Purchasing\PurchaseOrderMatch;
use App\Modules\Inventory\Purchasing\PurchaseProposal;
use App\Modules\ModuleDisabledException;
use App\Modules\ModuleManager;
use App\Support\Privacy\DataSubjectExport;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Purchasing;

/*
 * Inköpsorder and inköpsförslag (ADR-071 §2, §5, §12): rows in any unit the
 * article converts to, versions of a sent order, sending with the PDF,
 * receiving through goods receipts without a voucher, matching the supplier
 * invoice, and the purchase proposal from reorder points and open customer
 * orders.
 */

beforeEach(function () {
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->supplier = Purchasing::supplier($this->owner, $this->company);
    $this->stud = Purchasing::stud($this->owner, $this->company, $this->supplier, ['default_location_id' => $this->places['bin1']->id]);
    $this->screw = Purchasing::screw($this->owner, $this->company, $this->supplier, ['default_location_id' => $this->places['bin2']->id]);
    $this->m3 = Purchasing::unitId($this->company, 'm³');
    $this->forp = Purchasing::unitId($this->company, 'förp');
});

it('stores a row in the stock unit and keeps what was typed and the price per the row\'s unit (F-U1, F-U5)', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [
        ['article_id' => $this->stud->id, 'quantity' => '2,5', 'unit_id' => $this->m3, 'price' => '6 500'],
        ['article_id' => $this->screw->id, 'quantity' => '3', 'price' => '189'],
    ]);

    [$stud, $screw] = $order->rows->all();

    expect($order->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->source)->toBe(PurchaseOrderSource::Manual)
        ->and($order->number)->toBe(1)
        ->and($stud->quantity_milli)->toBe(584795)
        ->and($stud->entered_quantity)->toBe('2.5')
        ->and($stud->valueOre())->toBe(1625000)
        ->and($stud->stockUnitCostOre())->toBe(2779)
        ->and($stud->supplier_article_number)->toBe('GR-4595')
        ->and($screw->unit_id)->toBe($this->forp)
        ->and($screw->quantity_milli)->toBe(600000)
        ->and($screw->valueOre())->toBe(56700)
        ->and($screw->stockUnitCostOre())->toBe(95)
        ->and($order->totalOre())->toBe(1681700)
        ->and($order->delivery_address)->toContain('Lagergatan 1')
        ->and(AuditLog::query()->where('action', 'inventory.purchase_order_saved')->count())->toBe(1);
});

it('prices a row from the article\'s purchase price in the row\'s unit and refuses units that do not convert', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->stud->id, 'quantity' => '1', 'unit_id' => $this->m3]]);

    // 27,50 kr per lpm and 1 lpm = 0,004275 m³: 2750 / 0,004275 = 643 274,85 öre per m³.
    expect($order->rows->sole()->price_ore)->toBe(643275);

    expect(fn () => Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1', 'unit_id' => $this->m3]]))
        ->toThrow(ValidationException::class);

    expect(fn () => Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->stud->id, 'quantity' => '0,000001', 'unit_id' => $this->m3]]))
        ->toThrow(ValidationException::class);
});

it('warns when the stock quantity changes the typed one by more than 0,5 %', function () {
    // 0,001 m² of a 95 mm face is 0,0105 lpm, kept as 11 milli-lpm: back in m² 0,001045, 4,5 % more.
    $save = app(SavePurchaseOrder::class);
    $save($this->owner, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['article_id' => $this->stud->id, 'quantity' => '0,001', 'unit_id' => Purchasing::unitId($this->company, 'm²')]]]);

    expect($save->warnings)->toHaveCount(1)->and($save->warnings[0])->toContain('lpm');
});

it('refuses articles not kept in stock and suppliers under restricted processing', function () {
    $service = Inventory::article($this->company, $this->owner, 'TJ-1', 'Frakt', ['is_stock_item' => false]);

    expect(fn () => Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $service->id, 'quantity' => '1']]))->toThrow(ValidationException::class);

    $this->supplier->forceFill(['processing_restricted_at' => now()])->save();
    expect(fn () => Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1']]))->toThrow(ValidationException::class);
});

it('sends by e-mail with the PDF, from the company and with its reply-to, and keeps the version', function () {
    $this->company->forceFill(['email' => 'inkop@snickeri.example'])->save();
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '3', 'price' => '189']]);

    $version = app(SendPurchaseOrder::class)->email($this->owner, $this->company, $order, 'order@virkesgrossisten.example');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and($order->fresh()->sent_to)->toBe('order@virkesgrossisten.example')
        ->and($version->version)->toBe(1)
        ->and($version->total_ore)->toBe(56700)
        ->and($version->snapshot['rows'][0]['unit'])->toBe('förp')
        ->and($version->snapshot['rows'][0]['quantity'])->toBe('3');

    Mail::assertSent(PurchaseOrderMail::class, function (PurchaseOrderMail $mail): bool {
        $mail->assertHasReplyTo('inkop@snickeri.example');
        $mail->assertHasSubject("Inköpsorder 1 från {$this->company->legal_name}");

        return $mail->hasTo('order@virkesgrossisten.example') && count($mail->attachments()) === 1;
    });

    expect(PurchaseOrderDocument::render($this->company, $version->snapshot))->toStartWith('%PDF')
        ->and(AuditLog::query()->where('action', 'inventory.purchase_order_sent')->value('payload'))->not->toHaveKey('to');

    expect(fn () => app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order->fresh()))->toThrow(ValidationException::class);
});

it('changes a sent order only as a new version and keeps the sent one', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '3', 'price' => '189']]);
    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order);

    expect(fn () => app(SavePurchaseOrder::class)($this->owner, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['article_id' => $this->screw->id, 'quantity' => '5']]], $order->fresh()))
        ->toThrow(ValidationException::class);

    $revised = app(PurchaseOrderLifecycle::class)->revise($this->owner, $this->company, $order->fresh());
    $row = $revised->rows()->sole();
    app(SavePurchaseOrder::class)($this->owner, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $row->id, 'article_id' => $this->screw->id, 'quantity' => '5', 'price' => '189']]], $revised);
    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $revised->fresh());

    $versions = PurchaseOrderVersion::query()->where('purchase_order_id', $order->id)->orderBy('version')->get();

    expect($versions->pluck('version')->all())->toBe([1, 2])
        ->and($versions->pluck('total_ore')->all())->toBe([56700, 94500])
        ->and($order->fresh()->version)->toBe(2)
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and(fn () => $versions->first()->update(['total_ore' => 1]))->toThrow(LogicException::class);

    $this->get(route('inventory.purchaseOrders.pdf', ['purchaseOrder' => $order->id, 'version' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('receives through a goods receipt at the row\'s cost per stock unit, ticks off the order and books nothing (F-U1c)', function () {
    $vouchers = Voucher::query()->count();
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [
        ['article_id' => $this->stud->id, 'quantity' => '2,5', 'unit_id' => $this->m3, 'price' => '6 500'],
        ['article_id' => $this->screw->id, 'quantity' => '3', 'price' => '189'],
    ]);

    expect(fn () => app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $order))->toThrow(ValidationException::class);

    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order);
    $receipt = app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $order->fresh());
    $lines = $receipt->lines()->get();

    expect($receipt->purchase_order_id)->toBe($order->id)
        ->and($lines->pluck('ordered_milli')->all())->toBe([584795, 600000])
        ->and($lines->pluck('unit_cost_ore')->all())->toBe([2779, 95])
        ->and($lines->pluck('location_id')->all())->toBe([$this->places['bin1']->id, $this->places['bin2']->id])
        ->and(app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $order->fresh())->id)->toBe($receipt->id);

    app(ReceiveStock::class)($this->owner, $this->company, $receipt, [$lines[1]->id => ['quantity' => '400']], CarbonImmutable::parse('2026-07-01'));
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and($order->rows()->get()->pluck('received_milli')->all())->toBe([0, 400000]);

    app(ReceiveStock::class)->all($this->owner, $this->company, $receipt->fresh(), CarbonImmutable::parse('2026-07-02'));

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($order->rows()->get()->pluck('received_milli')->all())->toBe([584795, 600000])
        ->and(Inventory::level($this->stud, $this->places['bin1']))->toBe(584795)
        ->and(StockMovement::query()->where('article_id', $this->screw->id)->pluck('unit_cost_ore')->unique()->all())->toBe([95])
        ->and(Voucher::query()->count())->toBe($vouchers);

    expect(fn () => app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $order->fresh()))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveReceipt::class)($this->owner, $this->company, ['lines' => [['article_id' => $this->screw->id, 'quantity' => '1']]], $receipt->fresh()))->toThrow(ValidationException::class);
});

it('keeps received rows when a partly received order gets a new version', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '3', 'price' => '189']]);
    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order);
    $receipt = app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $order->fresh());
    app(ReceiveStock::class)($this->owner, $this->company, $receipt, [$receipt->lines()->sole()->id => ['quantity' => '200']], CarbonImmutable::parse('2026-07-01'));

    $revised = app(PurchaseOrderLifecycle::class)->revise($this->owner, $this->company, $order->fresh());
    $row = $revised->rows()->sole();

    expect(fn () => app(SavePurchaseOrder::class)($this->owner, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['article_id' => $this->stud->id, 'quantity' => '1']]], $revised))->toThrow(ValidationException::class)
        ->and(fn () => app(SavePurchaseOrder::class)($this->owner, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $row->id, 'article_id' => $this->screw->id, 'quantity' => '150', 'unit_id' => Purchasing::unitId($this->company, 'st')]]], $revised))->toThrow(ValidationException::class);

    app(SavePurchaseOrder::class)($this->owner, $this->company, ['supplier_id' => $this->supplier->id, 'rows' => [['id' => $row->id, 'article_id' => $this->screw->id, 'quantity' => '2', 'price' => '189']]], $revised);
    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $revised->fresh());

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);

    app(ReceiveStock::class)->all($this->owner, $this->company, $receipt->fresh(), CarbonImmutable::parse('2026-07-02'));
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received);
});

it('cancels an order nothing has arrived on, with its open receipt, and refuses one with goods', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '3']]);
    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order);
    $receipt = app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $order->fresh());

    app(PurchaseOrderLifecycle::class)->cancel($this->owner, $this->company, $order->fresh());

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($receipt->fresh()->status)->toBe(StockReceipt::CANCELLED);

    $other = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1']]);
    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $other);
    app(ReceiveStock::class)->all($this->owner, $this->company, app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $other->fresh()), CarbonImmutable::parse('2026-07-01'));

    expect(fn () => app(PurchaseOrderLifecycle::class)->cancel($this->owner, $this->company, $other->fresh()))->toThrow(ValidationException::class);
});

it('matches the supplier invoice and flags differences in quantity and price without approving or booking', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '3', 'price' => '189']]);
    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order);
    $receipt = app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $order->fresh());
    app(ReceiveStock::class)($this->owner, $this->company, $receipt, [$receipt->lines()->sole()->id => ['quantity' => '400']], CarbonImmutable::parse('2026-07-01'));

    $invoice = Purchasing::invoice($this->owner, $this->company, $this->supplier, '567,00');
    $vouchers = Voucher::query()->count();

    expect(PurchaseOrderMatch::forInvoice($invoice)->candidates->pluck('id')->all())->toBe([$order->id]);

    app(MatchSupplierInvoice::class)->link($this->owner, $this->company, $order, $invoice);
    $match = PurchaseOrderMatch::forInvoice($invoice);

    // Two of three packs arrived: 378 kr of goods against an invoice of 567 kr.
    expect($match->orderedOre)->toBe(56700)
        ->and($match->receivedOre)->toBe(37800)
        ->and($match->invoiceNetOre)->toBe(56700)
        ->and($match->flags)->toHaveCount(2)
        ->and($match->flags[0])->toContain('beställt 600 st, mottaget 400 st')
        ->and($match->flags[1])->toContain('högre än värdet')
        ->and($invoice->fresh()->approved_at)->toBeNull()
        ->and(Voucher::query()->count())->toBe($vouchers);

    app(ReceiveStock::class)->all($this->owner, $this->company, $receipt->fresh(), CarbonImmutable::parse('2026-07-02'));
    expect(PurchaseOrderMatch::forInvoice($invoice)->flags)->toBe([]);

    $otherSupplier = Purchasing::supplier($this->owner, $this->company, 'Beslagshuset AB', 'order@beslag.example');
    $otherInvoice = Purchasing::invoice($this->owner, $this->company, $otherSupplier, '100', 'B-1');
    expect(fn () => app(MatchSupplierInvoice::class)->link($this->owner, $this->company, $order, $otherInvoice))->toThrow(ValidationException::class);

    app(MatchSupplierInvoice::class)->unlink($this->owner, $this->company, $order, $invoice);
    expect(PurchaseOrderMatch::forInvoice($invoice)->orders)->toBeEmpty();
});

it('proposes what to order from reorder points, open customer orders and what is coming, rounded to whole packs', function () {
    // Screws: reorder point 500 st, reorder quantity 1 000 st, 300 in stock.
    app(SaveArticle::class)($this->owner, $this->company, ['number' => $this->screw->number, 'name' => $this->screw->name, 'price' => '2', 'vat_rate' => 2500, 'stock_unit' => 'st', 'purchase_unit' => 'förp', 'sales_unit' => 'st', 'reorder_point' => '500', 'reorder_quantity' => '1000'], $this->screw);
    Inventory::receive($this->company, $this->owner, $this->screw, $this->places['bin2'], '300', '0,95');

    // Studs: no reorder point, 10 lpm in stock, an open customer order for 40 lpm and a purchase order for 5 lpm on the way.
    Inventory::receive($this->company, $this->owner, $this->stud, $this->places['bin1'], '10', '27,50');
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kund AB', 'payment_terms_days' => 30]);
    app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::ORDER, ['customer_id' => $customer->id, 'document_date' => '2026-07-01', 'rows' => [
        ['article' => $this->stud->number, 'description' => 'Regel', 'quantity' => '40', 'unit' => 'lpm', 'price' => '49', 'vat_rate' => 2500],
    ]]);
    $coming = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->stud->id, 'quantity' => '5', 'unit_id' => Purchasing::unitId($this->company, 'lpm'), 'expected_on' => '2026-07-20']]);

    $lines = collect(app(PurchaseProposal::class)->lines($this->company))->keyBy(fn ($line) => $line->article->id);
    $screw = $lines[$this->screw->id];
    $stud = $lines[$this->stud->id];

    // 300 < 500: order max(1 000, 500 − 300) = 1 000 st, which is 5 packs of 200.
    expect($screw->belowReorderPoint)->toBeTrue()
        ->and($screw->proposedMilli)->toBe(1000000)
        ->and($screw->proposedQuantity)->toBe('5')
        ->and($screw->unit->code)->toBe('förp');

    // 40 reserved − 10 on hand − 5 coming = 25 lpm short = 0,106875 m³, rounded up to 0,107.
    expect($stud->reservedMilli)->toBe(40000)
        ->and($stud->comingMilli)->toBe(5000)
        ->and($stud->comingFrom)->toBe('2026-07-20')
        ->and($stud->shortMilli)->toBe(25000)
        ->and($stud->proposedQuantity)->toBe('0.107')
        ->and($stud->unit->code)->toBe('m³');

    $orders = app(CreatePurchaseOrdersFromProposal::class)($this->owner, $this->company, [
        ['article_id' => $this->screw->id, 'quantity' => '5', 'unit_id' => $this->forp],
        ['article_id' => $this->stud->id, 'quantity' => '0,107', 'unit_id' => $this->m3],
    ]);

    expect($orders)->toHaveCount(1)
        ->and($orders[0]->source)->toBe(PurchaseOrderSource::Proposal)
        ->and($orders[0]->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($orders[0]->rows->pluck('quantity_milli')->all())->toBe([1000000, 25029])
        ->and($coming->fresh()->status)->toBe(PurchaseOrderStatus::Draft);

    // The new drafts count as coming: nothing more is proposed for either article.
    expect(app(PurchaseProposal::class)->lines($this->company))->toBe([]);
});

it('counts a completed pick list for an order as taken, not reserved', function () {
    Inventory::receive($this->company, $this->owner, $this->screw, $this->places['bin2'], '100', '0,95');
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kund AB', 'payment_terms_days' => 30]);
    $order = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::ORDER, ['customer_id' => $customer->id, 'document_date' => '2026-07-01', 'rows' => [
        ['article' => $this->screw->number, 'description' => 'Skruv', 'quantity' => '150', 'unit' => 'st', 'price' => '2', 'vat_rate' => 2500],
    ]]);

    expect(collect(app(PurchaseProposal::class)->lines($this->company))->sole()->shortMilli)->toBe(50000);

    $list = app(PickLists::class)->fromSource($this->owner, $this->company, $order);

    foreach ($list->lines()->get() as $index => $line) {
        app(PickLists::class)->pick($this->owner, $this->company, $line, $index === 0 ? '100' : '0');
    }

    app(PickLists::class)->complete($this->owner, $this->company, $list, CarbonImmutable::parse('2026-07-02'));

    // 100 picked: 50 still reserved against 0 on hand.
    expect(collect(app(PurchaseProposal::class)->lines($this->company))->sole()->reservedMilli)->toBe(50000);
});

it('asks for a supplier when the article has none', function () {
    $loose = Purchasing::screw($this->owner, $this->company, null, ['number' => 'SKR-550', 'name' => 'Skruv 5×50']);

    expect(fn () => app(CreatePurchaseOrdersFromProposal::class)($this->owner, $this->company, [['article_id' => $loose->id, 'quantity' => '1', 'unit_id' => $this->forp]]))
        ->toThrow(ValidationException::class, 'Välj leverantör för SKR-550 Skruv 5×50.');
});

it('gives each role what ADR-071 §1 says, with sending above the approval amount for those who may send', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '3', 'price' => '189']]);
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $approver = Bookkeeping::member($this->company, 'approver');
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $accountant = Bookkeeping::member($this->company, 'accountant');
    $clerk = Bookkeeping::member($this->company, InventoryModule::ROLE);

    foreach ([$bookkeeper, $approver, $auditor, $accountant] as $user) {
        expect($user->hasTeamPermission($this->company, 'purchase-orders:view'))->toBeTrue();
    }

    expect($clerk->hasTeamPermission($this->company, 'purchase-orders:view'))->toBeFalse()
        ->and($approver->hasTeamPermission($this->company, 'purchase-orders:manage'))->toBeFalse()
        ->and($accountant->hasTeamPermission($this->company, 'purchase-orders:manage'))->toBeFalse()
        ->and(fn () => Purchasing::order($auditor, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1']]))->toThrow(AuthorizationException::class);

    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $this->company, $this->owner, ['purchase_order_approval_above_ore' => 50000]);

    expect(fn () => app(SendPurchaseOrder::class)->printed($bookkeeper, $this->company, $order))->toThrow(AuthorizationException::class);

    $small = Purchasing::order($bookkeeper, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1', 'price' => '189']]);
    app(SendPurchaseOrder::class)->printed($bookkeeper, $this->company, $small);
    app(SendPurchaseOrder::class)->printed($approver, $this->company, $order);

    expect($small->fresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and($order->fresh()->sent_by)->toBe($approver->id);
});

it('is off with Lager: no actions and no pages', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1']]);
    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, confirmed: true);

    expect(fn () => Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1']]))->toThrow(ModuleDisabledException::class)
        ->and(fn () => app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order))->toThrow(ModuleDisabledException::class);

    $this->get(route('inventory.purchaseOrders'))->assertNotFound();
    $this->get(route('inventory.purchaseOrders.show', $order->id))->assertNotFound();
    $this->get(route('inventory.purchaseProposals'))->assertNotFound();
});

it('puts the orders and where each version went in the supplier\'s register extract', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1']]);
    app(SendPurchaseOrder::class)->email($this->owner, $this->company, $order, 'order@virkesgrossisten.example');

    $extract = DataSubjectExport::supplier($this->supplier);

    expect($extract['inkopsorder'])->toHaveCount(1)
        ->and($extract['inkopsorder'][0]['nummer'])->toBe($order->number)
        ->and($extract['inkopsorder'][0]['skickade_versioner'][0]['till'])->toBe('order@virkesgrossisten.example');
});

it('lets an approver open and send orders without seeing the stock', function () {
    $approver = Bookkeeping::member($this->company, 'approver');
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1']]);
    $this->actingAs($approver);

    $this->get('/lager/inkopsorder')->assertOk()->assertSee('Beställningar');
    $this->get("/lager/inkopsorder/{$order->id}")->assertOk()->assertSee('Skicka till leverantören')->assertDontSee('Ta emot varor');
    $this->get('/lager')->assertForbidden();
});
