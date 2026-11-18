<?php

declare(strict_types=1);

use App\Actions\Payables\SaveSupplierInvoice;
use App\Livewire\Payables\SupplierInvoiceList;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\DimensionScopeFixture;

/*
 * ADR-081 gap 10: a limited member sees and registers only supplier invoices
 * whose rows are inside the scope; any other answers 404.
 */
beforeEach(function () {
    $this->fixture = DimensionScopeFixture::make();
    $this->member = $this->fixture->member();
    $this->actingAs($this->member);
    CurrentCompany::forget();
});

it('lists only the invoices inside the scope', function () {
    expect(Livewire::test(SupplierInvoiceList::class)->viewData('invoices')->pluck('id')->all())
        ->toBe([$this->fixture->invoices['inside']->id]);
});

it('opens an invoice inside the scope and answers 404 for one outside', function () {
    $this->get(route('payables.invoices.edit', $this->fixture->invoices['inside']))->assertOk();
    $this->get(route('payables.invoices.edit', $this->fixture->invoices['outside']))->assertNotFound();
    $this->get(route('payables.invoices.show', $this->fixture->invoices['outside']))->assertNotFound();
    $this->get(route('payables.invoices.file', $this->fixture->invoices['outside']))->assertNotFound();
});

it('registers only inside the scope', function () {
    $save = fn (string $costCenter) => app(SaveSupplierInvoice::class)($this->member, $this->fixture->company, [
        'supplier_id' => $this->fixture->supplier->id, 'invoice_number' => 'F-9', 'invoice_date' => '2026-03-06',
        'total' => '125', 'vat' => '25',
        'rows' => [['account' => '4010', 'amount' => '100', 'cost_center' => $costCenter]],
    ]);

    expect(fn () => $save('20'))->toThrow(ValidationException::class, 'måste höra till de enheter du når')
        ->and($save('10')->rows->sole()->cost_center_id)->toBe($this->fixture->north->id);
});

it('lets an unlimited member see every invoice', function () {
    $this->actingAs($this->fixture->owner);

    expect(Livewire::test(SupplierInvoiceList::class)->viewData('invoices')->total())->toBe(2);
});
