<?php

declare(strict_types=1);

use App\Actions\Payables\RegisterSupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\MatchSupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderEditor;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderMatchPanel;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderPage;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrdersPage;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseProposalsPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderInvoice;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\PurchaseOrderVersion;
use App\Modules\Inventory\Purchasing\PurchaseProposal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Purchasing;

/*
 * AGENTS.md §13.2 for inköpsorder (ADR-071 §2): company A never sees B's
 * orders, rows, sent versions or invoice matches, through Eloquent, the
 * pages, the PDF or the proposal; B's ids answer 404.
 */

beforeEach(function () {
    Mail::fake();

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Bookkeeping::company();
        $this->actingAs($owner);
        Inventory::enable($company, $owner);
        $supplier = Purchasing::supplier($owner, $company, "{$name} Trä AB", strtolower($name).'@example.com');
        $article = Purchasing::screw($owner, $company, $supplier, ['name' => "{$name}skruv", 'reorder_point' => '500']);
        $order = Purchasing::order($owner, $company, $supplier, [['article_id' => $article->id, 'quantity' => '1']]);
        app(SendPurchaseOrder::class)->printed($owner, $company, $order);
        $invoice = app(RegisterSupplierInvoice::class)($owner, Purchasing::invoice($owner, $company, $supplier, '189', "{$name}-1"));
        app(MatchSupplierInvoice::class)->link($owner, $company, $order, $invoice);
        $this->{$side} = compact('owner', 'company', 'supplier', 'article', 'order', 'invoice');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes every purchase order model to the current company', function (string $model) {
    expect($model::query()->count())->toBeGreaterThan(0)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([PurchaseOrder::class, PurchaseOrderRow::class, PurchaseOrderVersion::class, PurchaseOrderInvoice::class]);

it('answers 404 for the other company\'s orders on every page and the PDF', function () {
    $other = $this->b['order']->id;

    $this->get(route('inventory.purchaseOrders.show', $other))->assertNotFound();
    $this->get(route('inventory.purchaseOrders.edit', $other))->assertNotFound();
    $this->get(route('inventory.purchaseOrders.pdf', $other))->assertNotFound();
    $this->get(route('inventory.purchaseOrders.pdf', ['purchaseOrder' => $this->a['order']->id, 'version' => 1]))->assertOk();

    Livewire::test(PurchaseOrderPage::class, ['orderId' => $other])->assertNotFound();
    Livewire::test(PurchaseOrderEditor::class, ['orderId' => $other])->assertNotFound();
    Livewire::test(PurchaseOrderMatchPanel::class, ['invoiceId' => $this->b['invoice']->id])->assertNotFound();
    Livewire::test(PurchaseOrderMatchPanel::class, ['invoiceId' => $this->a['invoice']->id])->set('orderId', (string) $other)->call('link')->assertNotFound();
});

it('refuses the other company\'s order or invoice in the actions', function () {
    expect(fn () => app(SaveReceipt::class)->fromPurchaseOrder($this->a['owner'], $this->a['company'], $this->b['order']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(MatchSupplierInvoice::class)->link($this->a['owner'], $this->a['company'], $this->a['order'], $this->b['invoice']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => Purchasing::order($this->a['owner'], $this->a['company'], $this->b['supplier'], [['article_id' => $this->a['article']->id, 'quantity' => '1']]))->toThrow(ValidationException::class)
        ->and(fn () => Purchasing::order($this->a['owner'], $this->a['company'], $this->a['supplier'], [['article_id' => $this->b['article']->id, 'quantity' => '1']]))->toThrow(ValidationException::class);

    CurrentCompany::set($this->b['company']);
    expect(PurchaseOrderInvoice::query()->count())->toBe(1);
});

it('shows only the own company in the list and the proposal', function () {
    Livewire::test(PurchaseOrdersPage::class)->assertSee('Alfa Trä AB')->assertDontSee('Beta Trä AB');
    Livewire::test(PurchaseProposalsPage::class)->assertSee('Alfaskruv')->assertDontSee('Betaskruv');

    expect(collect(app(PurchaseProposal::class)->lines($this->a['company']))->map(fn ($line) => $line->article->name)->all())->toBe(['Alfaskruv']);
});
