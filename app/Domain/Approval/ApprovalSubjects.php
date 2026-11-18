<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\SupplierInvoice;
use App\Models\SupplierPaymentBatch;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\PurchaseOrderApprovals;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * The subjects wired to the engine: how each is loaded (inside the current
 * company, so another company's id is not found), locked, described and
 * whether it still waits for approval.
 */
final class ApprovalSubjects
{
    /** Loads and locks the subject in the current company; another company's id throws ModelNotFoundException. */
    public static function lock(ApprovalSubjectType $type, string $id): SupplierInvoice|SupplierPaymentBatch|PurchaseOrder
    {
        return match ($type) {
            ApprovalSubjectType::PurchaseOrder => self::lockPurchaseOrder($id),
            ApprovalSubjectType::SupplierInvoice => SupplierInvoice::query()->whereKey($id)->lockForUpdate()->firstOrFail(),
            ApprovalSubjectType::PaymentBatch => SupplierPaymentBatch::query()->whereKey($id)->lockForUpdate()->firstOrFail(),
            default => throw new InvalidArgumentException("{$type->value} is not wired to the approval engine yet."),
        };
    }

    public static function facts(Model $subject): SubjectFacts
    {
        return match (true) {
            $subject instanceof SupplierInvoice => SubjectFacts::forSupplierInvoice($subject),
            $subject instanceof SupplierPaymentBatch => SubjectFacts::forPaymentBatch($subject),
            $subject instanceof PurchaseOrder => SubjectFacts::forPurchaseOrder($subject),
            default => throw new InvalidArgumentException('Not an approval subject.'),
        };
    }

    /** Whether the subject still waits for its approval. */
    public static function awaiting(Model $subject): bool
    {
        return match (true) {
            $subject instanceof SupplierInvoice => $subject->status === SupplierInvoiceStatus::Registered,
            $subject instanceof SupplierPaymentBatch => $subject->isOpen() && ! $subject->isApproved(),
            $subject instanceof PurchaseOrder => $subject->isDraft() && ! $subject->isApproved(),
            default => false,
        };
    }

    /** The permission that prepares the subject, which may also send it back to approval. */
    public static function preparePermission(ApprovalSubjectType $type): string
    {
        return match ($type) {
            ApprovalSubjectType::PaymentBatch => 'payments:create',
            ApprovalSubjectType::PurchaseOrder => InventoryModule::PURCHASE_MANAGE,
            default => 'supplier-invoices:create',
        };
    }

    /** Purchase orders belong to Lager: with it off, no order is found. */
    private static function lockPurchaseOrder(string $id): PurchaseOrder
    {
        if (! app(ModuleManager::class)->isEnabled(InventoryModule::KEY, CurrentCompany::id())) {
            throw new ModelNotFoundException;
        }

        return PurchaseOrderApprovals::lock($id);
    }
}
