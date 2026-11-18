<?php

declare(strict_types=1);

namespace App\Domain\Approval;

/**
 * What an approval policy applies to (ADR-081 gap 8). Supplier invoices and
 * payment files are wired in E2 and purchase orders in E10; purchase
 * requisitions are wired by their own package (E9) and cannot be chosen until then.
 */
enum ApprovalSubjectType: string
{
    case SupplierInvoice = 'SUPPLIER_INVOICE';
    case PaymentBatch = 'PAYMENT_BATCH';
    case PurchaseRequisition = 'PURCHASE_REQUISITION';
    case PurchaseOrder = 'PURCHASE_ORDER';

    public function label(): string
    {
        return match ($this) {
            self::SupplierInvoice => __('Leverantörsfakturor'),
            self::PaymentBatch => __('Betalfiler'),
            self::PurchaseRequisition => __('Inköpsanmodan'),
            self::PurchaseOrder => __('Inköpsorder'),
        };
    }

    /** Whether the subject's own approval runs through the engine yet. */
    public function isWired(): bool
    {
        return in_array($this, self::wired(), true);
    }

    /** @return list<self> */
    public static function wired(): array
    {
        return [self::SupplierInvoice, self::PaymentBatch, self::PurchaseOrder];
    }

    /**
     * The permission the subject's approval needs today, and so the one the
     * default policy names. A purchase order is approved by whoever may send
     * it (purchase-orders:send, the approver role); a requisition gets its own
     * when it is wired (E9).
     */
    public function basePermission(): string
    {
        return match ($this) {
            self::PaymentBatch => 'payments:approve',
            self::PurchaseOrder => 'purchase-orders:send',
            self::SupplierInvoice, self::PurchaseRequisition => 'supplier-invoices:approve',
        };
    }

    /**
     * Which step conditions mean something for the subject. A payment file
     * holds many invoices, so only its total can be tested.
     *
     * @return list<string>
     */
    public function conditionKeys(): array
    {
        return match ($this) {
            self::PaymentBatch => ['min_ore', 'max_ore'],
            default => StepConditions::KEYS,
        };
    }

    /** @return list<ApproverKind> */
    public function approverKinds(): array
    {
        return match ($this) {
            self::PaymentBatch => [ApproverKind::Permission, ApproverKind::Users],
            default => ApproverKind::cases(),
        };
    }

    /**
     * The permissions a step may name: the subject's own, or the owners and
     * administrators (approvals:manage).
     *
     * @return list<string>
     */
    public function stepPermissions(): array
    {
        return [$this->basePermission(), 'approvals:manage'];
    }
}
