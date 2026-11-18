<?php

declare(strict_types=1);

use App\Actions\Payables\RegisterSupplierInvoice;
use App\Models\SupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\AllocateSupplierInvoiceRows;
use App\Modules\Inventory\Actions\Purchasing\MatchSupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\OverridePurchaseMatch;
use App\Modules\Inventory\Actions\Purchasing\RecomputePurchaseMatch;
use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderMatchPanel;
use App\Modules\Inventory\Models\PurchaseMatchResult;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Purchasing;

/*
 * AGENTS.md §13.2 for three-way matching (ADR-081 E11): another company's
 * supplier invoice, its stored results and its purchase order rows answer
 * 404 (or are simply not choosable) from every action and the panel, and
 * nothing of the other company changes.
 */
beforeEach(function () {
    Mail::fake();
    [$this->ownerA, $this->a] = Bookkeeping::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();

    [$this->invoiceB, $this->resultB, $this->rowB] = CurrentCompany::run($this->b, function (): array {
        Inventory::enable($this->b, $this->ownerB);
        $supplier = Purchasing::supplier($this->ownerB, $this->b);
        $order = Purchasing::order($this->ownerB, $this->b, $supplier, [['description' => 'Hemlig tjänst', 'account' => '4010', 'quantity' => '1', 'price' => '1000']]);
        app(SendPurchaseOrder::class)->printed($this->ownerB, $this->b, $order);
        $invoice = app(RegisterSupplierInvoice::class)($this->ownerB, Purchasing::invoice($this->ownerB, $this->b, $supplier, '1000'));
        app(MatchSupplierInvoice::class)->link($this->ownerB, $this->b, $order, $invoice);

        return [$invoice->fresh(), RecomputePurchaseMatch::current($invoice)->sole(), $order->rows()->sole()];
    });

    CurrentCompany::set($this->a);
    $this->actingAs($this->ownerA);
    Inventory::enable($this->a, $this->ownerA);
    $this->supplierA = Purchasing::supplier($this->ownerA, $this->a);
});

function e11TenancyInvoiceA(object $test): SupplierInvoice
{
    $order = Purchasing::order($test->ownerA, $test->a, $test->supplierA, [['description' => 'Egen tjänst', 'account' => '4010', 'quantity' => '1', 'price' => '1000']]);
    app(SendPurchaseOrder::class)->printed($test->ownerA, $test->a, $order);
    $invoice = app(RegisterSupplierInvoice::class)($test->ownerA, Purchasing::invoice($test->ownerA, $test->a, $test->supplierA, '1000', 'A-1'));
    app(MatchSupplierInvoice::class)->link($test->ownerA, $test->a, $order, $invoice);

    return $invoice->fresh();
}

it('answers 404 from every matching action for another company\'s invoice and result', function () {
    $invoiceA = e11TenancyInvoiceA($this);

    expect(fn () => app(OverridePurchaseMatch::class)($this->ownerA, $this->a, $this->invoiceB, $this->resultB->id, 'Skäl'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(OverridePurchaseMatch::class)($this->ownerA, $this->a, $invoiceA, $this->resultB->id, 'Skäl'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(AllocateSupplierInvoiceRows::class)->save($this->ownerA, $this->a, $this->invoiceB, []))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(AllocateSupplierInvoiceRows::class)->suggest($this->ownerA, $this->a, $this->invoiceB))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RecomputePurchaseMatch::class)($this->a, $this->invoiceB, $this->ownerA))->toThrow(ModelNotFoundException::class);

    CurrentCompany::run($this->b, function (): void {
        expect(PurchaseMatchResult::query()->sole()->status->value)->toBe('UNMATCHED')
            ->and(PurchaseMatchResult::query()->sole()->superseded_at)->toBeNull();
    });
});

it('never lets another company\'s order row be chosen for an invoice row', function () {
    $invoiceA = e11TenancyInvoiceA($this);
    $rowA = $invoiceA->rows()->sole();

    expect(fn () => app(AllocateSupplierInvoiceRows::class)->save($this->ownerA, $this->a, $invoiceA, [$rowA->id => ['order_row' => $this->rowB->id, 'quantity' => '1']]))->toThrow(ValidationException::class)
        ->and(PurchaseOrderRow::query()->find($this->rowB->id))->toBeNull();
});

it('answers 404 on the panel and the stored results of another company', function () {
    Livewire::test(PurchaseOrderMatchPanel::class, ['invoiceId' => $this->invoiceB->id])->assertNotFound();

    $invoiceA = e11TenancyInvoiceA($this);

    Livewire::test(PurchaseOrderMatchPanel::class, ['invoiceId' => $invoiceA->id])
        ->call('startOverride', $this->resultB->id)
        ->assertNotFound();

    expect(PurchaseMatchResult::query()->find($this->resultB->id))->toBeNull()
        ->and(RecomputePurchaseMatch::current($invoiceA))->toHaveCount(1);
});
