<?php

declare(strict_types=1);

use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\ResubmitApproval;
use App\Domain\Approval\ApprovalSubjectType;
use App\Modules\Inventory\Actions\Purchasing\ApprovePurchaseRequisition;
use App\Modules\Inventory\Actions\Purchasing\ConvertPurchaseRequisitions;
use App\Modules\Inventory\Actions\Purchasing\PurchaseRequisitionLifecycle;
use App\Modules\Inventory\Actions\Purchasing\RejectPurchaseRequisition;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseRequisition;
use App\Modules\Inventory\Livewire\Purchasing\RequisitionEditor;
use App\Modules\Inventory\Livewire\Purchasing\RequisitionPage;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Models\PurchaseRequisitionRow;
use App\Modules\Inventory\Purchasing\PurchaseRequisitionApprovals;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Purchasing;

/*
 * AGENTS.md §13.2 for purchase requisitions (ADR-081 E9): another company's
 * requisition, its rows and its approval round answer 404 from every action and
 * page, its rows are never converted into this company's order, and its policy
 * never decides this company's requisition.
 */
beforeEach(function () {
    [$this->ownerA, $this->a] = Bookkeeping::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();
    $this->approverB = Bookkeeping::member($this->b, 'approver');
    $this->b = $this->b->fresh();

    [$this->requisitionB, $this->rowB] = CurrentCompany::run($this->b, function (): array {
        Inventory::enable($this->b, $this->ownerB);
        $requisition = app(SavePurchaseRequisition::class)($this->ownerB, $this->b, ['reason' => 'Hemligt skäl', 'rows' => [['description' => 'Hemlig tjänst', 'quantity' => '1', 'price' => '100', 'account' => '4010']]]);
        app(PurchaseRequisitionLifecycle::class)->submit($this->ownerB, $this->b, $requisition);
        app(ApprovePurchaseRequisition::class)($this->approverB, $this->b, PurchaseRequisitionApprovals::subjectId($requisition->fresh()));

        return [$requisition->fresh(), $requisition->rows()->sole()];
    });

    CurrentCompany::set($this->a);
    $this->actingAs($this->ownerA);
    Inventory::enable($this->a, $this->ownerA);
    $this->supplierA = Purchasing::supplier($this->ownerA, $this->a);
});

it('answers 404 from every action for another company\'s requisition', function () {
    $subject = PurchaseRequisitionApprovals::subjectId($this->requisitionB);

    expect(fn () => app(ApprovePurchaseRequisition::class)($this->ownerA, $this->a, $subject))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RejectPurchaseRequisition::class)($this->ownerA, $this->a, $subject))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RejectApproval::class)($this->ownerA, $this->a, ApprovalSubjectType::PurchaseRequisition, $subject))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ResubmitApproval::class)($this->ownerA, $this->a, ApprovalSubjectType::PurchaseRequisition, $subject))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RejectApproval::class)($this->ownerA, $this->a, ApprovalSubjectType::PurchaseRequisition, 'not-an-id'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(PurchaseRequisitionLifecycle::class)->cancel($this->ownerA, $this->a, $this->requisitionB))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SavePurchaseRequisition::class)($this->ownerA, $this->a, ['rows' => [['description' => 'x', 'quantity' => '1', 'account' => '4010']]], $this->requisitionB))->toThrow(ModelNotFoundException::class);

    CurrentCompany::run($this->b, function (): void {
        expect(PurchaseRequisition::query()->sole()->status->value)->toBe('APPROVED');
    });
});

it('never converts another company\'s rows into this company\'s order', function () {
    expect(fn () => app(ConvertPurchaseRequisitions::class)($this->ownerA, $this->a, [$this->rowB->id], $this->supplierA->id))->toThrow(ValidationException::class, 'finns inte längre');

    CurrentCompany::run($this->b, function (): void {
        expect(PurchaseRequisitionRow::query()->sole()->purchase_order_row_id)->toBeNull()
            ->and(PurchaseRequisition::query()->sole()->status->value)->toBe('APPROVED');
    });
});

it('answers 404 on the pages of another company', function () {
    Livewire::test(RequisitionPage::class, ['requisitionId' => $this->requisitionB->id])->assertNotFound();
    Livewire::test(RequisitionEditor::class, ['requisitionId' => $this->requisitionB->id])->assertNotFound();
    $this->get(route('inventory.requisitions.show', $this->requisitionB->id))->assertNotFound();
    $this->get(route('inventory.requisitions.edit', $this->requisitionB->id))->assertNotFound();
});

it('lets the other company\'s policy never decide this company\'s requisition', function () {
    CurrentCompany::run($this->b, fn () => ApprovalFixtures::policy($this->ownerB, $this->b, [['approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage']], type: 'PURCHASE_REQUISITION'));

    $own = app(SavePurchaseRequisition::class)($this->ownerA, $this->a, ['rows' => [['description' => 'Egen tjänst', 'quantity' => '1', 'price' => '100', 'account' => '4010']]]);
    $this->a->forceFill(['separate_supplier_approval' => false])->save();
    app(PurchaseRequisitionLifecycle::class)->submit($this->ownerA, $this->a, $own);
    $approved = app(ApprovePurchaseRequisition::class)($this->ownerA, $this->a->fresh(), PurchaseRequisitionApprovals::subjectId($own->fresh()));

    expect($approved->status->value)->toBe('APPROVED')
        ->and($approved->approval_request_id)->toBeNull();
});
