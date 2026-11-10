<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Models\SalesDocument;
use App\Modules\Inventory\Actions\Purchasing\MatchSupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderEditor;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderMatchPanel;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderPage;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrdersPage;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseProposalsPage;
use App\Modules\Inventory\Livewire\ReceiptsPage;
use App\Modules\Inventory\Mail\PurchaseOrderMail;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\StockReceipt;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Purchasing;

/*
 * The purchase order screens (ADR-071 §2, §5, §12.5): the list, the editor
 * with the stock-unit equivalent, the order page with sending after the PDF
 * and the recipient, receiving on Inleveranser, the proposal, the panel on
 * the supplier invoice, and a fixed number of queries for the lists.
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
});

it('shows the pages under their Swedish addresses and in the Lager menu', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->stud->id, 'quantity' => '2,5', 'unit_id' => $this->m3, 'price' => '6 500']]);

    $this->get('/lager/inkopsorder')->assertOk()->assertSee('Inköpsorder')->assertSee('Virkesgrossisten AB')->assertSee('Inköpsförslag');
    $this->get("/lager/inkopsorder/{$order->id}")->assertOk()->assertSee('2,5 m³')->assertSee('≈ 584,795 lpm')->assertSee(Money::ofOre(1625000)->format(withCurrency: false));
    $this->get("/lager/inkopsorder/{$order->id}/andra")->assertOk()->assertSee('Spara utkast');
    $this->get('/lager/inkopsorder/ny')->assertOk()->assertSee('Ny inköpsorder');
    $this->get('/lager/inkopsforslag')->assertOk()->assertSee('Inköpsförslag');
    $this->get("/lager/inkopsorder/{$order->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('creates a draft in the editor with the purchase unit chosen and the stock equivalent shown', function () {
    Livewire::test(PurchaseOrderEditor::class)
        ->set('form.supplier_id', (string) $this->supplier->id)
        ->set('form.rows.0.article_id', (string) $this->stud->id)
        ->assertSet('form.rows.0.unit_id', (string) $this->m3)
        ->set('form.rows.0.quantity', '2,5')
        ->assertSee('≈ 584,795 lpm')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('inventory.purchaseOrders.show', PurchaseOrder::query()->sole()->id));

    $row = PurchaseOrder::query()->sole()->rows()->sole();
    expect($row->quantity_milli)->toBe(584795)->and($row->price_ore)->toBe(643275);
});

it('shows the PDF and the recipient before sending, then sends by e-mail', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '3', 'price' => '189']]);

    Livewire::test(PurchaseOrderPage::class, ['orderId' => $order->id])
        ->call('openSend')
        ->assertSet('email', 'order@virkesgrossisten.example')
        ->assertSee(route('inventory.purchaseOrders.pdf', $order->id))
        ->assertSee('Kontrollera PDF:en och mottagaren')
        ->call('sendEmail')
        ->assertDispatched('toast', message: 'Inköpsordern är skickad till order@virkesgrossisten.example.', type: 'success');

    Mail::assertSent(PurchaseOrderMail::class);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Sent);
});

it('opens the goods receipt for the order on Inleveranser', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '3']]);
    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order);

    Livewire::test(PurchaseOrderPage::class, ['orderId' => $order->id])->call('receive')
        ->assertRedirect(route('inventory.receipts', ['inleverans' => StockReceipt::query()->sole()->id]));

    Livewire::withQueryParams(['inleverans' => (string) StockReceipt::query()->sole()->id])->test(ReceiptsPage::class)
        ->assertSet('receiving', StockReceipt::query()->sole()->id)
        ->call('receiveGoods');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received);

    $next = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1']]);
    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $next);

    Livewire::test(ReceiptsPage::class)->assertSee($next->title())
        ->set('purchaseOrderId', (string) $next->id)
        ->call('receivePurchaseOrder')
        ->assertSet('receiving', StockReceipt::query()->where('purchase_order_id', $next->id)->sole()->id);
});

it('creates drafts from the proposal page', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kund AB', 'payment_terms_days' => 30]);
    app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::ORDER, ['customer_id' => $customer->id, 'document_date' => '2026-07-01', 'rows' => [
        ['article' => $this->screw->number, 'description' => 'Skruv', 'quantity' => '450', 'unit' => 'st', 'price' => '2', 'vat_rate' => 2500],
    ]]);

    Livewire::test(PurchaseProposalsPage::class)
        ->assertSee('SKR-440')
        ->assertSet("choices.{$this->screw->id}.quantity", '3')
        ->call('create')
        ->assertRedirect(route('inventory.purchaseOrders', ['visa' => 'utkast']));

    expect(PurchaseOrder::query()->sole()->rows()->sole()->quantity_milli)->toBe(600000);
});

it('shows and makes the match on the supplier invoice page', function () {
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '3', 'price' => '189']]);
    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order);
    $invoice = app(RegisterSupplierInvoice::class)($this->owner, Purchasing::invoice($this->owner, $this->company, $this->supplier, '567'));

    $this->get(route('payables.invoices.show', $invoice))->assertOk()->assertSee('Koppla till inköpsorder');

    Livewire::test(PurchaseOrderMatchPanel::class, ['invoiceId' => $invoice->id])
        ->set('orderId', (string) $order->id)
        ->call('link')
        ->assertSee('Inköpsorder 1')
        ->assertSee('mottaget 0 st');

    expect($order->supplierInvoices()->pluck('supplier_invoices.id')->all())->toBe([$invoice->id]);
});

it('hides the purchase orders from those without the permission', function () {
    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->screw->id, 'quantity' => '1']]);
    $invoice = Purchasing::invoice($this->owner, $this->company, $this->supplier, '100');
    $this->actingAs($clerk);

    $this->get('/lager/inkopsorder')->assertForbidden();
    $this->get("/lager/inkopsorder/{$order->id}")->assertForbidden();
    $this->get('/lager')->assertOk()->assertDontSee('Inköpsförslag');

    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->actingAs($auditor);
    $this->get("/lager/inkopsorder/{$order->id}")->assertOk()->assertDontSee('Skicka till leverantören');
    $this->get('/lager/inkopsorder/ny')->assertForbidden();
    Livewire::test(PurchaseOrderMatchPanel::class, ['invoiceId' => $invoice->id])->assertDontSee('Koppla till inköpsorder');
});

it('lists orders and computes the proposal with the same number of queries for few and many', function () {
    $count = function (Closure $render): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $orders = function (int $from, int $to): void {
        foreach (range($from, $to) as $index) {
            $article = Purchasing::screw($this->owner, $this->company, $this->supplier, ['number' => "SKR-{$index}", 'name' => "Skruv {$index}", 'reorder_point' => '100']);
            $order = Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $article->id, 'quantity' => '1'], ['article_id' => $this->stud->id, 'quantity' => '1', 'unit_id' => $this->m3]]);
            app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order);
            $invoice = Purchasing::invoice($this->owner, $this->company, $this->supplier, '100', "F-{$index}");
            app(MatchSupplierInvoice::class)->link($this->owner, $this->company, $order, $invoice);
            app(SaveReceipt::class)->fromPurchaseOrder($this->owner, $this->company, $order);
        }
    };

    $orders(1, 2);
    $warm = [
        $count(fn () => Livewire::test(PurchaseOrdersPage::class)),
        $count(fn () => Livewire::test(PurchaseProposalsPage::class)),
    ];
    $few = [
        $count(fn () => Livewire::test(PurchaseOrdersPage::class)),
        $count(fn () => Livewire::test(PurchaseProposalsPage::class)),
        $count(fn () => Livewire::test(PurchaseOrderPage::class, ['orderId' => PurchaseOrder::query()->min('id')])),
        $count(fn () => Livewire::test(ReceiptsPage::class)),
    ];

    $orders(3, 12);
    $many = [
        $count(fn () => Livewire::test(PurchaseOrdersPage::class)),
        $count(fn () => Livewire::test(PurchaseProposalsPage::class)),
        $count(fn () => Livewire::test(PurchaseOrderPage::class, ['orderId' => PurchaseOrder::query()->min('id')])),
        $count(fn () => Livewire::test(ReceiptsPage::class)),
    ];

    expect($many)->toBe($few);
});
