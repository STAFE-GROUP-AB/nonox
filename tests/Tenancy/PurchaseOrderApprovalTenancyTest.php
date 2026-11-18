<?php

declare(strict_types=1);

use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\ResubmitApproval;
use App\Domain\Approval\ApprovalSubjectType;
use App\Modules\Inventory\Actions\Purchasing\ApprovePurchaseOrder;
use App\Modules\Inventory\Actions\Purchasing\ConfirmOrderRowDelivery;
use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\PurchaseOrderApprovals;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Purchasing;

/*
 * AGENTS.md §13.2 for purchase order approval and rows without an article
 * (ADR-081 E10): another company's order, its rows and its approval round
 * answer 404 from every action and the page, and its policy never decides
 * this company's order.
 */
beforeEach(function () {
    Mail::fake();
    [$this->ownerA, $this->a] = Bookkeeping::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();
    $this->approverB = Bookkeeping::member($this->b, 'approver');
    $this->b = $this->b->fresh();

    [$this->orderB, $this->serviceRowB] = CurrentCompany::run($this->b, function (): array {
        Inventory::enable($this->b, $this->ownerB);
        $supplier = Purchasing::supplier($this->ownerB, $this->b);
        ApprovalFixtures::policy($this->ownerB, $this->b, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'purchase-orders:send', 'exclude_preparer' => false]], type: 'PURCHASE_ORDER');
        $order = Purchasing::order($this->ownerB, $this->b, $supplier, [['description' => 'Hemlig tjänst', 'account' => '4010', 'quantity' => '1', 'price' => '1000']]);
        app(ApprovePurchaseOrder::class)($this->approverB, $this->b, PurchaseOrderApprovals::subjectId($order->fresh()));
        app(SendPurchaseOrder::class)->printed($this->ownerB, $this->b, $order->fresh());

        return [$order->fresh(), $order->rows()->sole()];
    });

    CurrentCompany::set($this->a);
    $this->actingAs($this->ownerA);
    Inventory::enable($this->a, $this->ownerA);
    $this->supplierA = Purchasing::supplier($this->ownerA, $this->a);
});

it('answers 404 from every approval and delivery action for another company\'s order', function () {
    $subject = PurchaseOrderApprovals::subjectId($this->orderB);

    expect(fn () => app(ApprovePurchaseOrder::class)($this->ownerA, $this->a, PurchaseOrderApprovals::subjectId($this->orderB)))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ConfirmOrderRowDelivery::class)($this->ownerA, $this->a, $this->orderB, $this->serviceRowB->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RejectApproval::class)($this->ownerA, $this->a, ApprovalSubjectType::PurchaseOrder, $subject))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ResubmitApproval::class)($this->ownerA, $this->a, ApprovalSubjectType::PurchaseOrder, $subject))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RejectApproval::class)($this->ownerA, $this->a, ApprovalSubjectType::PurchaseOrder, 'not-an-id'))->toThrow(ModelNotFoundException::class);

    CurrentCompany::run($this->b, function (): void {
        expect($this->serviceRowB->fresh()->confirmed_at)->toBeNull()
            ->and(PurchaseOrder::query()->sole()->approval_request_id)->not->toBeNull();
    });
});

it('answers 404 on the order page of another company', function () {
    Livewire::test(PurchaseOrderPage::class, ['orderId' => $this->orderB->id])->assertNotFound();
    $this->get(route('inventory.purchaseOrders.show', $this->orderB->id))->assertNotFound();
});

it('lets the other company\'s policy never decide this company\'s order', function () {
    $order = Purchasing::order($this->ownerA, $this->a, $this->supplierA, [['description' => 'Egen tjänst', 'account' => '4010', 'quantity' => '1', 'price' => '1000']]);

    app(SendPurchaseOrder::class)->printed($this->ownerA, $this->a, $order);

    expect($order->fresh()->status->value)->toBe('SENT')
        ->and($order->versions()->sole()->approval_request_id)->toBeNull();
});
